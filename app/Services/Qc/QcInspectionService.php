<?php

namespace App\Services\Qc;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QcCertificate;
use App\Models\QcDevice;
use App\Models\QcInspection;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\QcAuthorization;
use App\Services\Products\ProductTitleService;
use App\Services\ReferenceSequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QcInspectionService
{
    public function __construct(private readonly QcAuthorization $authorization, private readonly LaptopQcTemplate $template, private readonly ActivityLogger $activity, private readonly ReferenceSequenceService $references) {}

    public function visible(User $actor): Builder
    {
        $this->authorization->authorize($actor, QcPermission::View);

        return QcInspection::query()->when(! $this->authorization->allows($actor, QcPermission::ViewAll), fn ($query) => $query->where('technician_user_id', $actor->id));
    }

    public static function serialKey(string $serial): string
    {
        return hash('sha256', mb_strtoupper(trim($serial)));
    }

    public function start(array $data, User $actor): QcInspection
    {
        $this->authorization->authorize($actor, QcPermission::Start);
        $data = Validator::make($data, [
            'product_id' => ['required', 'integer', 'exists:products,id'], 'serial' => ['required', 'string', 'min:3', 'max:100', 'regex:/\A[A-Za-z0-9._ -]+\z/'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'order_item_id' => ['nullable', 'integer', 'exists:order_items,id'],
            'features' => ['array'], 'features.*' => ['string'],
            'special_requirement' => ['nullable', 'string', 'max:500'], 'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $fingerprint = hash('sha256', json_encode(Arr::except($data, 'idempotency_key'), JSON_THROW_ON_ERROR));
        if ($existing = QcInspection::query()->where('technician_user_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first()) {
            if ($existing->request_fingerprint !== $fingerprint) {
                throw ValidationException::withMessages(['serial' => 'This start request was already used for different device details.']);
            }

            return $existing;
        }
        $key = self::serialKey($data['serial']);
        if (QcDevice::query()->where('serial_key', $key)->exists()) {
            throw ValidationException::withMessages(['serial' => 'This device already exists. Open its QC job or use authorized Re-QC.']);
        }
        // Reserve before the business transaction; a rolled-back reference is never reused.
        $reference = $this->references->nextQcReference();
        try {
            return DB::transaction(function () use ($data, $actor, $key, $reference, $fingerprint): QcInspection {
                $product = Product::query()->active()->products()->lockForUpdate()->find($data['product_id']);
                if (! $product || ! Warehouse::query()->active()->whereKey($data['warehouse_id'])->exists()) {
                    throw ValidationException::withMessages(['product_id' => 'Choose an active Product and location.']);
                }
                $item = empty($data['order_item_id']) ? null : OrderItem::query()->with('upgradeSelection')->findOrFail($data['order_item_id']);
                if ($item && ($item->product_id !== $product->id || $item->order->warehouse_id !== (int) $data['warehouse_id'])) {
                    throw ValidationException::withMessages(['order_item_id' => 'The Order item must match this Product and location.']);
                }
                $template = app(QcTemplateResolver::class)->resolve($product);
                Validator::make($data, ['features.*' => ['in:'.implode(',', array_keys($template['features']))]])->validate();
                $device = QcDevice::query()->create(['reference' => $reference, 'serial' => trim($data['serial']), 'serial_key' => $key, 'device_type' => $template['device_type'], 'product_id' => $product->id]);
                $requested = $item?->upgradeSelection ? Arr::only($item->upgradeSelection->configuration_snapshot, ['display_name', 'target_ram_mb', 'target_storage_total_gb', 'target_storage_layout']) : null;
                if (filled($data['special_requirement'] ?? null)) {
                    $requested = ($requested ?? []) + ['special_requirement' => $data['special_requirement']];
                }
                $ram = preg_match('/\A(\d+)\s*GB\z/i', trim((string) $product->ram), $match) ? (int) $match[1] * 1024 : null;
                $storage = preg_match('/\A(\d+)\s*(GB|TB)\z/i', trim((string) $product->storage), $match) ? (int) $match[1] * (strtoupper($match[2]) === 'TB' ? 1024 : 1) : null;
                $original = ['cpu' => $product->processor ?? $product->processor_model, 'ram' => $product->ram, 'storage' => $product->storage, 'ram_mb' => $ram, 'storage_gb' => $storage, 'os' => null];
                $original['hardware_profile_version'] = $item?->upgradeSelection?->hardware_profile_version ?? $product->hardwareProfile?->profile_version;
                $features = $data['features'] ?? [];
                if ($product->touch_screen === true) {
                    $features[] = 'touchscreen';
                }
                $inspection = QcInspection::query()->create([
                    'device_id' => $device->id, 'active_device_id' => $device->id, 'version' => 1, 'technician_user_id' => $actor->id,
                    'warehouse_id' => $data['warehouse_id'], 'order_id' => $item?->order_id, 'order_item_id' => $item?->id,
                    'order_snapshot' => $item ? ['reference' => $item->order->reference, 'line_key' => $item->line_key, 'line_number' => $item->line_number] : null,
                    'status' => QcInspectionStatus::Pending, 'idempotency_key' => $data['idempotency_key'], 'request_fingerprint' => $fingerprint,
                    'product_snapshot' => ['sku' => $product->sku, 'title' => app(ProductTitleService::class)->accounting($product), 'brand' => $product->brandRelation?->name ?? $product->brand, 'category' => $product->categoryRelation?->name, 'condition' => $product->condition?->value, 'touch_screen' => $product->touch_screen, 'qc_template' => $template],
                    'original_configuration' => $original, 'final_configuration' => $original, 'requested_configuration' => $requested, 'features' => array_values(array_unique($features)),
                ]);
                $this->initializeChecks($inspection);
                $this->activity->log('qc.started', $actor, $inspection, ['reference' => $reference, 'version' => 1]);

                return $inspection;
            }, 5);
        } catch (QueryException $exception) {
            $existing = QcInspection::query()->where('technician_user_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing && $existing->request_fingerprint === $fingerprint) {
                return $existing;
            }
            if (QcDevice::query()->where('serial_key', $key)->exists()) {
                throw ValidationException::withMessages(['serial' => 'This device already has a QC job. Open the existing job.']);
            }
            throw $exception;
        }
    }

    private function initializeChecks(QcInspection $inspection): void
    {
        $position = 0;
        foreach (app(QcTemplateResolver::class)->forInspection($inspection)['checks'] as $key => $definition) {
            if ($key === 'touchscreen' && ($inspection->product_snapshot['touch_screen'] ?? false)) {
                $definition['mandatory'] = true;
                $definition['allows_na'] = false;
            }
            $applicable = (! $definition['upgrade'] || filled($inspection->requested_configuration)) && (! $definition['feature'] || in_array($definition['feature'], $inspection->features, true));
            $inspection->checks()->create(['check_key' => $key, 'sort_order' => ++$position, 'definition' => $definition, 'applicable' => $applicable, 'result' => $applicable ? null : 'na']);
        }
    }

    public function update(QcInspection $inspection, array $data, User $actor): QcInspection
    {
        $this->authorization->authorize($actor, QcPermission::Update, $inspection);

        return DB::transaction(function () use ($inspection, $data, $actor): QcInspection {
            $inspection = $this->editable($inspection);
            $safe = Validator::make($data, ['final_configuration' => ['array'], 'final_configuration.cpu' => ['nullable', 'string', 'max:255'], 'final_configuration.ram_mb' => ['nullable', 'integer', 'min:1', 'max:1048576'], 'final_configuration.storage_gb' => ['nullable', 'numeric', 'min:1', 'max:262144'], 'final_configuration.os' => ['nullable', 'string', 'max:120'], 'grade' => ['nullable', 'in:A,B,C'], 'public_remarks' => ['nullable', 'string', 'max:2000'], 'internal_remarks' => ['nullable', 'string', 'max:4000'], 'checks' => ['array']])->validate();
            if ($this->hasUpgrade($inspection, $safe['final_configuration'] ?? $inspection->final_configuration)) {
                foreach ($inspection->checks()->where('applicable', false)->get()->filter(fn ($check) => $check->definition['upgrade']) as $check) {
                    $check->update(['applicable' => true, 'result' => null]);
                }
            }
            foreach ($data['checks'] ?? [] as $key => $input) {
                $check = $inspection->checks()->where('check_key', $key)->first();
                if (! $check || ! $check->applicable) {
                    throw ValidationException::withMessages(['checks.'.$key => 'This check does not apply to this inspection.']);
                }
                $definition = $check->definition;
                $rules = ['result' => ['nullable', 'in:pass,fail'.($definition['allows_na'] ? ',na' : '')], 'notes' => ['nullable', 'string', 'max:2000']];
                if ($definition['measurement']) {
                    [$min, $max] = $definition['measurement'];
                    $rules['measurement'] = ['required_if:result,pass', 'nullable', 'numeric', 'between:'.$min.','.$max];
                }
                if (($input['result'] ?? null) === 'fail') {
                    $rules['notes'][] = 'required';
                }
                try {
                    $validated = Validator::make($input, $rules)->validate();
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['checks.'.$key.'.'.$field => array_map(fn ($message) => $definition['label'].': '.$message, $messages)])->all());
                }
                $check->update($validated + ['detail' => ($validated['result'] ?? null) === 'pass' ? $definition['pass_text'] : null]);
            }
            unset($safe['checks']);
            $safe['final_configuration'] = Arr::only($safe['final_configuration'] ?? $inspection->final_configuration ?? [], ['cpu', 'ram_mb', 'storage_gb', 'os']);
            $safe['status'] = $inspection->checks()->where('result', 'fail')->exists() ? QcInspectionStatus::Rework : QcInspectionStatus::InProgress;
            $inspection->update($safe);
            $this->activity->log($safe['status'] === QcInspectionStatus::Rework ? 'qc.failed' : 'qc.updated', $actor, $inspection);

            return $inspection->refresh();
        }, 5);
    }

    public function passGroup(QcInspection $inspection, string $group, User $actor): void
    {
        $checks = $inspection->checks()->where('applicable', true)->get()->filter(fn ($check) => $check->definition['group'] === $group && $check->definition['measurement'] === null && $check->result !== 'fail');
        if ($checks->isEmpty()) {
            throw ValidationException::withMessages(['checks' => 'This group has no checks eligible for Pass All.']);
        }
        $this->update($inspection, ['checks' => $checks->mapWithKeys(fn ($check) => [$check->check_key => ['result' => 'pass']])->all()], $actor);
    }

    public function editable(QcInspection $inspection): QcInspection
    {
        $locked = QcInspection::query()->lockForUpdate()->findOrFail($inspection->id);
        if ($locked->status === QcInspectionStatus::Completed) {
            throw ValidationException::withMessages(['checks' => 'Completed QC cannot be edited. Use authorized Re-QC.']);
        }

        return $locked;
    }

    public function begin(QcInspection $inspection, User $actor): QcInspection
    {
        $this->authorization->authorize($actor, QcPermission::Update, $inspection);

        return DB::transaction(function () use ($inspection, $actor): QcInspection {
            $locked = QcInspection::query()->lockForUpdate()->findOrFail($inspection->id);
            if ($locked->status === QcInspectionStatus::Completed) {
                throw ValidationException::withMessages(['inspection' => 'Completed QC cannot be reopened. Use authorized Re-QC.']);
            }
            if ($locked->status === QcInspectionStatus::Pending) {
                $locked->update(['status' => QcInspectionStatus::InProgress]);
                $this->activity->log('qc.updated', $actor, $locked, ['action' => 'mobile_begin']);
            }

            return $locked->refresh();
        }, 5);
    }

    public function hasUpgrade(QcInspection $inspection, ?array $final = null): bool
    {
        if (filled($inspection->requested_configuration)) {
            return true;
        }
        $final ??= $inspection->final_configuration ?? [];
        foreach (['ram_mb', 'storage_gb'] as $field) {
            if (isset($inspection->original_configuration[$field], $final[$field]) && is_numeric($final[$field]) && (float) $inspection->original_configuration[$field] !== (float) $final[$field]) {
                return true;
            }
        }

        return false;
    }

    public function complete(QcInspection $inspection, User $actor): QcCertificate
    {
        $this->authorization->authorize($actor, QcPermission::Complete, $inspection);

        return DB::transaction(function () use ($inspection, $actor): QcCertificate {
            QcDevice::query()->whereKey($inspection->device_id)->lockForUpdate()->firstOrFail();
            $inspection = QcInspection::query()->lockForUpdate()->findOrFail($inspection->id);
            if ($inspection->status === QcInspectionStatus::Completed) {
                return $inspection->certificate()->firstOrFail();
            }
            $finalFields = app(QcTemplateResolver::class)->forInspection($inspection)['final_fields'];
            Validator::make($inspection->toArray(), ['grade' => ['required', 'in:A,B,C'], 'final_configuration.cpu' => [in_array('cpu', $finalFields, true) ? 'required' : 'nullable', 'string'], 'final_configuration.ram_mb' => [in_array('ram_mb', $finalFields, true) ? 'required' : 'nullable', 'integer', 'min:1'], 'final_configuration.storage_gb' => ['required', 'numeric', 'min:1'], 'final_configuration.os' => ['required', 'string']])->validate();
            $checks = $inspection->checks()->get();
            $errors = [];
            foreach ($checks->where('applicable', true) as $check) {
                if (! in_array($check->result, ['pass', 'na'], true) || ($check->result === 'na' && ! $check->definition['allows_na']) || ($check->result === 'pass' && $check->definition['measurement'] !== null && $check->measurement === null)) {
                    $errors['checks.'.$check->check_key.'.result'] = $check->definition['label'].' must be tested successfully before completion.';
                }
            }
            $requested = $inspection->requested_configuration ?? [];
            if (isset($requested['target_ram_mb']) && (int) $inspection->final_configuration['ram_mb'] !== (int) $requested['target_ram_mb']) {
                $errors['final_configuration.ram_mb'] = 'Final tested RAM must match the customer-required configuration.';
            }
            if (isset($requested['target_storage_total_gb']) && (float) $inspection->final_configuration['storage_gb'] !== (float) $requested['target_storage_total_gb']) {
                $errors['final_configuration.storage_gb'] = 'Final tested storage must match the customer-required configuration.';
            }
            $evidence = $inspection->evidence()->where('customer_visible', true)->get();
            $kinds = app(QcEvidenceService::class)->requiredKinds($inspection);
            foreach ($kinds as $kind) {
                if (! $evidence->contains(fn ($proof) => $proof->kind === $kind && Storage::disk('local')->exists($proof->original_path) && Storage::disk('local')->exists($proof->customer_path))) {
                    $errors['evidence.'.$kind] = 'Upload customer-visible '.QcEvidenceService::KINDS[$kind].' proof before completing QC.';
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $time = now();
            // Render the existing accounting title on an unsaved per-unit copy, using tested specs.
            $displayProduct = clone $inspection->device->product;
            $tablet = app(QcTemplateResolver::class)->forInspection($inspection)['device_type'] === 'tablet';
            // Structured accounting formatting is reused only on this unsaved display copy;
            // it neither selects the QC template nor changes the Product's actual category.
            $displayProduct->forceFill(['category' => 'Laptop', 'accounting_title_override' => null, 'processor' => $inspection->final_configuration['cpu'] ?? null, 'processor_class' => null, 'processor_generation' => null, 'ram' => isset($inspection->final_configuration['ram_mb']) ? ($inspection->final_configuration['ram_mb'] / 1024).'GB' : null, 'storage' => $inspection->final_configuration['storage_gb'].'GB']);
            $displayProduct->unsetRelation('categoryRelation');
            $productSnapshot = $inspection->product_snapshot;
            $productSnapshot['title'] = app(ProductTitleService::class)->accounting($displayProduct);
            $productSnapshot['label_title'] = $tablet ? trim($displayProduct->displayBrandName().' '.$displayProduct->model) ?: $productSnapshot['title'] : (trim(Str::before($productSnapshot['title'], $inspection->final_configuration['cpu'])) ?: $productSnapshot['title']);
            // Keep public snapshots small: definitions remain on the inspection/check results.
            unset($productSnapshot['qc_template']);
            $productSnapshot['device_type'] = app(QcTemplateResolver::class)->forInspection($inspection)['device_type'];
            $certificate = QcCertificate::query()->create(['inspection_id' => $inspection->id, 'device_id' => $inspection->device_id, 'version' => $inspection->version, 'public_token' => bin2hex(random_bytes(32)), 'certified_at' => $time,
                'snapshot' => ['reference' => $inspection->device->reference, 'serial' => $inspection->device->serial, 'product' => $productSnapshot, 'original' => $inspection->original_configuration, 'requested' => $requested, 'final' => $inspection->final_configuration, 'grade' => $inspection->grade, 'remarks' => $inspection->public_remarks, 'technician' => $actor->employee->name, 'certified_at' => $time->toIso8601String(), 'checks' => $checks->where('applicable', true)->where('result', 'pass')->map(fn ($check) => ['key' => $check->check_key, 'label' => $check->definition['label'], 'group' => $check->definition['group'], 'result' => 'pass', 'detail' => $check->detail, 'measurement' => $check->measurement])->values()->all(), 'evidence' => $evidence->pluck('public_id')->all()]]);
            $inspection->update(['status' => QcInspectionStatus::Completed, 'completed_at' => $time, 'active_device_id' => null]);
            $this->activity->log('qc.completed', $actor, $inspection, ['reference' => $inspection->device->reference, 'version' => $inspection->version]);
            if ($inspection->version > 1) {
                $this->activity->log('qc.superseded', $actor, $inspection, ['previous_version' => $inspection->version - 1]);
            }

            return $certificate;
        }, 5);
    }

    public function reopen(QcInspection $inspection, string $reason, User $actor): QcInspection
    {
        $this->authorization->authorize($actor, QcPermission::Reopen, $inspection);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($inspection, $reason, $actor): QcInspection {
            QcDevice::query()->whereKey($inspection->device_id)->lockForUpdate()->firstOrFail();
            $inspection = $inspection->fresh();
            if ($inspection->status !== QcInspectionStatus::Completed || ! $inspection->certificate->isCurrent() || QcInspection::query()->where('active_device_id', $inspection->device_id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Only the current completed certificate can start one new reinspection.']);
            }
            $new = $inspection->replicate(['active_device_id', 'idempotency_key', 'completed_at']);
            $new->fill(['version' => $inspection->version + 1, 'status' => QcInspectionStatus::Pending, 'grade' => null, 'completed_at' => null, 'active_device_id' => $inspection->device_id, 'idempotency_key' => (string) str()->uuid(), 'technician_user_id' => $inspection->technician_user_id, 'reinspection_reason' => $reason]);
            $new->save();
            $this->initializeChecks($new);
            $this->activity->log('qc.reopened', $actor, $new, ['version' => $new->version]);

            return $new;
        }, 5);
    }
}
