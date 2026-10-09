<?php

namespace App\Services\Qc;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QcCertificate;
use App\Models\QcDevice;
use App\Models\QcInspection;
use App\Models\QcOrderAssignment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QcOrderAssignmentService
{
    public function allows(User $actor, QcPermission $permission, Order $order): bool
    {
        return app(OrderAuthorization::class)->allows($actor, OrderPermission::View, $order)
            && app(QcAuthorization::class)->allows($actor, QcPermission::ViewOrderAssignments)
            && app(QcAuthorization::class)->allows($actor, $permission);
    }

    private function authorize(User $actor, QcPermission $permission, Order $order): void
    {
        throw_unless($this->allows($actor, $permission, $order), AuthorizationException::class);
    }

    public function canChange(Order $order, bool $release = false): bool
    {
        return ($release || ! in_array($order->status, [OrderStatus::Draft, OrderStatus::Cancelled], true))
            && $order->status !== OrderStatus::Fulfilled && $order->delivered_at === null
            && ! $order->fulfillment()->exists() && ! $order->items()->whereHas('fulfillmentItem')->exists();
    }

    public function assign(OrderItem $item, int $certificateId, User $actor): QcOrderAssignment
    {
        $this->authorize($actor, QcPermission::AssignOrderDevice, $item->order);

        return DB::transaction(function () use ($item, $certificateId, $actor): QcOrderAssignment {
            // Order lock serializes shipment/amendment; device lock is shared with
            // complete/reopen. Item lock serializes unit-cap checks across devices.
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($item->id);
            $this->authorize($actor, QcPermission::AssignOrderDevice, $order);
            if (! $this->canChange($order)) {
                throw ValidationException::withMessages(['order_item_id' => 'Assign devices after Draft and before shipment. Cancelled or shipped Orders cannot receive devices.']);
            }
            $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
            if (! app(RenewedQcRequirement::class)->conditionRequires($product->condition)) {
                throw ValidationException::withMessages(['order_item_id' => 'QC device assignments are only available for Renewed Order Items.']);
            }
            $certificate = QcCertificate::query()->find($certificateId);
            if (! $certificate) {
                throw ValidationException::withMessages(['certificate_id' => 'Select a completed, current QC certificate.']);
            }
            QcDevice::query()->whereKey($certificate->device_id)->lockForUpdate()->firstOrFail();
            $inspection = QcInspection::query()->lockForUpdate()->findOrFail($certificate->inspection_id);
            $certificate->setRelation('inspection', $inspection);
            // Dispatch permission grants only the narrow assignment projection,
            // not access to another technician's internal inspection/evidence.
            if (! app(QcAuthorization::class)->allows($actor, QcPermission::ScanDispatch)) {
                app(QcAuthorization::class)->authorize($actor, QcPermission::View, $inspection);
            }
            $this->validateCertificate($item, $order, $certificate, locked: true);
            $existing = QcOrderAssignment::query()->where('active_device_id', $certificate->device_id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->order_item_id === $item->id && $existing->qc_certificate_id === $certificate->id) {
                    return $existing; // Browser retry/double click: no duplicate audit or row.
                }
                throw ValidationException::withMessages(['certificate_id' => 'This QC device is assigned to another Order or Order Item. Release its existing assignment explicitly first.']);
            }
            if ($item->qcAssignments()->active()->count() >= $item->ordered_quantity) {
                throw ValidationException::withMessages(['order_item_id' => 'All units on this Order Item already have QC devices assigned.']);
            }
            $assignment = QcOrderAssignment::query()->create([
                'order_id' => $order->id, 'order_item_id' => $item->id,
                'qc_device_id' => $certificate->device_id, 'active_device_id' => $certificate->device_id,
                'qc_certificate_id' => $certificate->id, 'certificate_version' => $certificate->version,
                'assigned_by_user_id' => $actor->id, 'assigned_at' => now(),
            ]);
            $this->audit('qc.order_device_assigned', $assignment, $actor);

            return $assignment;
        }, 5);
    }

    public function validateCertificate(OrderItem $item, Order $order, QcCertificate $certificate, bool $locked = false): void
    {
        $inspection = $certificate->inspection;
        // With the device mutex held, use current locking reads rather than a
        // MySQL repeatable-read snapshot taken before waiting for that mutex.
        $newer = QcCertificate::query()->where('device_id', $certificate->device_id)->where('version', '>', $certificate->version);
        $pending = QcInspection::query()->where('active_device_id', $certificate->device_id);
        $stale = $locked ? $newer->lockForUpdate()->get()->isNotEmpty() : $newer->exists();
        $reinspection = $locked ? $pending->lockForUpdate()->get()->isNotEmpty() : $pending->exists();
        if ($inspection->status !== QcInspectionStatus::Completed || $stale || $reinspection) {
            throw ValidationException::withMessages(['certificate_id' => 'QC must be completed and current, with no reinspection pending.']);
        }
        if ($certificate->device->product_id !== $item->product_id) {
            throw ValidationException::withMessages(['certificate_id' => 'The QC device Product does not match this Order Item.']);
        }
        if ($inspection->warehouse_id !== $order->warehouse_id) {
            throw ValidationException::withMessages(['certificate_id' => 'The QC location must match the Order fulfilment warehouse. No stock transfer is performed.']);
        }
        foreach (['target_ram_mb' => 'ram_mb', 'target_storage_total_gb' => 'storage_gb'] as $requested => $final) {
            $target = $item->upgradeSelection?->configuration_snapshot[$requested] ?? null;
            if ($target !== null && (int) ($certificate->snapshot['final'][$final] ?? -1) !== (int) $target) {
                throw ValidationException::withMessages(['certificate_id' => 'Final tested '.($final === 'ram_mb' ? 'RAM' : 'storage').' must match the structured Order configuration.']);
            }
        }
    }

    public function release(QcOrderAssignment $assignment, string $reason, User $actor): QcOrderAssignment
    {
        $this->authorize($actor, QcPermission::ReleaseOrderAssignment, $assignment->order);
        $reason = Validator::make(['release_reason' => $reason], ['release_reason' => ['required', 'string', 'max:2000']])->validate()['release_reason'];

        return DB::transaction(function () use ($assignment, $reason, $actor): QcOrderAssignment {
            $order = Order::query()->lockForUpdate()->findOrFail($assignment->order_id);
            OrderItem::query()->whereKey($assignment->order_item_id)->lockForUpdate()->firstOrFail();
            QcDevice::query()->whereKey($assignment->qc_device_id)->lockForUpdate()->firstOrFail();
            $assignment = QcOrderAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $this->authorize($actor, QcPermission::ReleaseOrderAssignment, $order);
            if (! $this->canChange($order, release: true)) {
                throw ValidationException::withMessages(['assignment_id' => 'Shipped devices cannot be released or reassigned through this workflow.']);
            }
            if ($assignment->released_at !== null) {
                return $assignment;
            }
            $assignment->fill(['active_device_id' => null, 'released_at' => now(), 'released_by_user_id' => $actor->id, 'release_reason' => trim($reason)])->save();
            $this->audit('qc.order_device_released', $assignment, $actor, ['release_reason' => trim($reason)]);

            return $assignment;
        }, 5);
    }

    private function audit(string $event, QcOrderAssignment $assignment, User $actor, array $extra = []): void
    {
        app(ActivityLogger::class)->log($event, $actor, $assignment, $extra + [
            'order_id' => $assignment->order_id, 'order_reference' => $assignment->order->reference,
            'order_item_id' => $assignment->order_item_id, 'device_id' => $assignment->qc_device_id,
            'qc_reference' => $assignment->device->reference, 'certificate_version' => $assignment->certificate_version,
        ]);
    }

    public function history(Order $order, User $actor): Collection
    {
        $this->authorize($actor, QcPermission::ViewOrderAssignments, $order);

        return $order->items()->select(['id', 'order_id', 'product_id', 'sku', 'product_name', 'ordered_quantity'])
            ->with(['qcAssignments.device', 'qcAssignments.certificate.inspection', 'qcAssignments.assignedBy:id,name', 'qcAssignments.releasedBy:id,name'])->get();
    }

    public function candidates(OrderItem $item, User $actor, string $search = ''): Builder
    {
        $item = $item->fresh(['order', 'upgradeSelection', 'product']);
        $this->authorize($actor, QcPermission::AssignOrderDevice, $item->order);
        $query = QcCertificate::query()->with(['device', 'inspection.warehouse'])
            ->whereHas('device', fn ($q) => $q->where('product_id', $item->product_id)->whereDoesntHave('orderAssignments', fn ($a) => $a->active()))
            ->whereHas('inspection', function ($q) use ($item, $actor): void {
                $q->where('status', QcInspectionStatus::Completed->value)->where('warehouse_id', $item->order->warehouse_id);
                if (! app(QcAuthorization::class)->allows($actor, QcPermission::ViewAll) && ! app(QcAuthorization::class)->allows($actor, QcPermission::ScanDispatch)) {
                    $q->where('technician_user_id', $actor->id);
                }
            })
            ->whereDoesntHave('device.inspections', fn ($q) => $q->whereNotNull('active_device_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('qc_certificates as newer')->whereColumn('newer.device_id', 'qc_certificates.device_id')->whereColumn('newer.version', '>', 'qc_certificates.version'));
        foreach (['target_ram_mb' => 'ram_mb', 'target_storage_total_gb' => 'storage_gb'] as $requested => $final) {
            $target = $item->upgradeSelection?->configuration_snapshot[$requested] ?? null;
            if ($target !== null) {
                $query->where('snapshot->final->'.$final, (int) $target);
            }
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->whereHas('device', fn ($q) => $q->where(fn ($q) => $q->whereRaw("serial LIKE ? ESCAPE '!'", [$like])->orWhereRaw("reference LIKE ? ESCAPE '!'", [$like])));
        }
        if (! app(RenewedQcRequirement::class)->requires($item) || ! $this->canChange($item->order) || $item->qcAssignments()->active()->count() >= $item->ordered_quantity) {
            $query->whereRaw('1 = 0');
        }

        return $query->orderByDesc('certified_at')->orderByDesc('id');
    }

    public function label(QcCertificate $certificate): string
    {
        $s = $certificate->snapshot;

        return $s['reference'].' · v'.$certificate->version.' · '.$s['serial'].' · '.$s['product']['label_title']
            .' · '.($s['final']['ram_mb'] ?? '—').' MB / '.($s['final']['storage_gb'] ?? '—').' GB · Grade '.$s['grade']
            .' · '.$certificate->inspection->warehouse->name.' · '.app(BusinessTimezone::class)->format($certificate->certified_at, 'd M Y');
    }

    public function resolveScan(OrderItem $item, string $value, User $actor, bool $dispatch = false): QcCertificate
    {
        if (! app(RenewedQcRequirement::class)->requires($item->fresh('product'))) {
            throw ValidationException::withMessages(['order_item_id' => 'QC device assignments are only available for Renewed Order Items.']);
        }
        Validator::make(['certificate_id' => $value], ['certificate_id' => ['required', 'string', 'max:2048']])->validate();
        $value = trim($value);
        $token = null;
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $expected = parse_url(app(QcDocumentService::class)->url(new QcCertificate(['public_token' => str_repeat('0', 64)])));
            $url = parse_url($value);
            $path = preg_quote($expected['path'], '~');
            $path = str_replace(str_repeat('0', 64), '([a-f0-9]{64})', $path);
            if (($url['scheme'] ?? '') !== ($expected['scheme'] ?? '') || ($url['host'] ?? '') !== ($expected['host'] ?? '')
                || ($url['port'] ?? null) !== ($expected['port'] ?? null) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment']) || ! preg_match('~\A'.$path.'\z~', $url['path'] ?? '', $match)) {
                throw ValidationException::withMessages(['certificate_id' => 'Scan a TPZ QC verification QR or enter a device Serial / QC ID. External URLs are not accepted.']);
            }
            $token = $match[1];
        } elseif (! preg_match('/\A[A-Za-z0-9._ -]{3,100}\z/', $value)) {
            throw ValidationException::withMessages(['certificate_id' => 'Enter a valid device Serial / IMEI or QC ID.']);
        }
        if ($dispatch) {
            $this->authorize($actor, QcPermission::ScanDispatch, $item->order);
        }
        $query = $dispatch ? QcCertificate::query()->with(['device', 'inspection']) : $this->candidates($item, $actor);
        $token !== null ? $query->where('public_token', $token) : $query->whereHas('device', fn ($q) => $q->where(fn ($q) => $q->where('serial_key', QcInspectionService::serialKey($value))->orWhere('reference', strtoupper($value))));
        $certificate = $query->first();
        if (! $certificate) {
            throw ValidationException::withMessages(['certificate_id' => 'No eligible, authorized current QC device matches this Order Item.']);
        }

        return $certificate;
    }

    public function resolveDispatchCertificate(string $value, User $actor, Order $order): QcCertificate
    {
        $this->authorize($actor, QcPermission::ScanDispatch, $order);
        Validator::make(['certificate_id' => $value], ['certificate_id' => ['required', 'string', 'max:2048']])->validate();
        $value = trim($value);
        $token = null;
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $expected = parse_url(app(QcDocumentService::class)->url(new QcCertificate(['public_token' => str_repeat('0', 64)])));
            $url = parse_url($value);
            $path = preg_quote($expected['path'], '~');
            $path = str_replace(str_repeat('0', 64), '([a-f0-9]{64})', $path);
            if (($url['scheme'] ?? '') !== ($expected['scheme'] ?? '') || ($url['host'] ?? '') !== ($expected['host'] ?? '')
                || ($url['port'] ?? null) !== ($expected['port'] ?? null) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment']) || ! preg_match('~\A'.$path.'\z~', $url['path'] ?? '', $match)) {
                throw ValidationException::withMessages(['code' => 'Scan a TPZ QC verification QR or enter a device Serial / QC ID. External URLs are not accepted.']);
            }
            $token = $match[1];
        } elseif (! preg_match('/\A[A-Za-z0-9._ -]{3,100}\z/', $value)) {
            throw ValidationException::withMessages(['code' => 'Enter a valid device Serial / IMEI or QC ID.']);
        }

        $query = QcCertificate::query()->with(['device', 'inspection']);
        $token !== null ? $query->where('public_token', $token) : $query->whereHas('device', fn ($q) => $q->where(fn ($q) => $q->where('serial_key', QcInspectionService::serialKey($value))->orWhere('reference', strtoupper($value))));
        $certificate = $query->first();
        if (! $certificate) {
            throw ValidationException::withMessages(['code' => 'No eligible current QC device matches the scanned label.']);
        }

        return $certificate;
    }
}
