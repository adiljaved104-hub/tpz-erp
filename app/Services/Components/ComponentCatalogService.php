<?php

namespace App\Services\Components;

use App\Enums\ComponentPermission;
use App\Enums\ComponentType;
use App\Enums\InventoryItemType;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Component;
use App\Models\ComponentRecoveryValueEvent;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ComponentAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComponentCatalogService
{
    public function __construct(
        private readonly ComponentAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Component
    {
        $this->authorization->authorize($actor, ComponentPermission::Create);
        $validated = $this->validate($data);
        $recovery = $this->recoveryValue($data);
        $this->authorizeRecovery($recovery, $actor);
        $sku = $this->references->nextProductSku();

        return DB::transaction(function () use ($validated, $recovery, $sku, $actor): Component {
            $brand = ProductBrand::query()->lockForUpdate()->whereKey($validated['brand_id'])->where('status', true)->firstOrFail();
            $category = ProductCategory::query()->lockForUpdate()->whereKey($validated['category_id'])->where('status', true)->firstOrFail();
            $product = new Product;
            $product->forceFill([
                'sku' => $sku,
                'inventory_item_type' => InventoryItemType::Component,
                'name' => $validated['name'],
                'brand' => $brand->name,
                'brand_id' => $brand->id,
                'category' => $category->name,
                'category_id' => $category->id,
                'model' => $validated['model'] ?? null,
                'condition' => ProductCondition::New,
                'warranty' => 0,
                'cost_price' => null,
                'selling_price' => '0.00',
                'description' => $validated['description'] ?? null,
                'status' => ProductStatus::Active,
            ])->save();

            $component = Component::query()->create([
                'product_id' => $product->id,
                ...$this->componentAttributes($validated),
                ...$this->recoveryAttributes($recovery, $validated['recovery_reason'] ?? null, $actor),
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            if (bccomp($recovery, '0.0000', 4) > 0) {
                $this->recordRecoveryEvent($component, '0.0000', $recovery, $validated['recovery_reason'], $actor);
            }

            $this->activity->log('component.created', $actor, $component);

            return $component->load('product');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Component $component, array $data, User $actor): Component
    {
        $this->authorization->authorize($actor, ComponentPermission::Update, $component);
        $validated = $this->validate($data, $component);

        return DB::transaction(function () use ($component, $validated, $actor): Component {
            $component = Component::query()->lockForUpdate()->findOrFail($component->id);
            $product = Product::query()->lockForUpdate()->findOrFail($component->product_id);
            $brand = ProductBrand::query()->lockForUpdate()->findOrFail($validated['brand_id']);
            $category = ProductCategory::query()->lockForUpdate()->findOrFail($validated['category_id']);

            if ((! $brand->status && $brand->id !== $product->brand_id)
                || (! $category->status && $category->id !== $product->category_id)) {
                throw ValidationException::withMessages(['brand_id' => 'Only active catalog values may be newly assigned.']);
            }

            $product->forceFill([
                'name' => $validated['name'], 'brand_id' => $brand->id, 'brand' => $brand->name,
                'category_id' => $category->id, 'category' => $category->name,
                'model' => $validated['model'] ?? null, 'description' => $validated['description'] ?? null,
            ])->save();
            $component->forceFill([...$this->componentAttributes($validated), 'updated_by_user_id' => $actor->id])->save();
            $this->activity->log('component.updated', $actor, $component);

            return $component->load('product');
        });
    }

    public function updateApprovedRecoveryValue(Component $component, string $value, string $reason, User $actor): Component
    {
        $this->authorization->authorize($actor, ComponentPermission::ApproveRecoveryValue, $component);
        $validated = Validator::make(compact('value', 'reason'), [
            'value' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ])->validate();
        $value = number_format((float) $validated['value'], 4, '.', '');

        return DB::transaction(function () use ($component, $value, $validated, $actor): Component {
            $component = Component::query()->lockForUpdate()->findOrFail($component->id);
            $old = (string) $component->approved_oem_recovery_value;

            if (bccomp($old, $value, 4) === 0) {
                return $component;
            }

            $component->forceFill([
                ...$this->recoveryAttributes($value, $validated['reason'], $actor),
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->recordRecoveryEvent($component, $old, $value, $validated['reason'], $actor);
            $this->activity->log('component.recovery_value_approved', $actor, $component);

            return $component;
        });
    }

    public function changeStatus(Component $component, ProductStatus $status, string $reason, User $actor): Component
    {
        $this->authorization->authorize($actor, ComponentPermission::ChangeStatus, $component);
        Validator::make(compact('reason'), ['reason' => ['required', 'string', 'min:5', 'max:1000']])->validate();

        return DB::transaction(function () use ($component, $status, $actor): Component {
            $component = Component::query()->lockForUpdate()->findOrFail($component->id);
            Product::query()->lockForUpdate()->findOrFail($component->product_id)->forceFill(['status' => $status])->save();
            $this->activity->log('component.status_changed', $actor, $component);

            return $component->load('product');
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validate(array $data, ?Component $component = null): array
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'brand_id' => ['required', 'integer', $component === null ? Rule::exists('product_brands', 'id')->where('status', true) : Rule::exists('product_brands', 'id')],
            'category_id' => ['required', 'integer', $component === null ? Rule::exists('product_categories', 'id')->where('status', true) : Rule::exists('product_categories', 'id')],
            'model' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'component_type' => ['required', Rule::enum(ComponentType::class)],
            'specification' => ['required', 'string', 'max:255'],
            'capacity_value' => ['nullable', 'numeric', 'gt:0'],
            'capacity_unit' => ['nullable', Rule::in(['mb', 'gb', 'tb', 'mah', 'other']), 'required_with:capacity_value'],
            'interface_type' => ['nullable', 'string', 'max:120'],
            'approved_oem_recovery_value' => ['nullable', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/'],
            'recovery_reason' => ['nullable', 'string', 'min:5', 'max:1000'],
        ])->after(function ($validator) use ($data): void {
            if (blank($data['capacity_value'] ?? null) xor blank($data['capacity_unit'] ?? null)) {
                $validator->errors()->add('capacity_value', 'Capacity value and unit must be provided together.');
            }
            if (bccomp($this->recoveryValue($data), '0.0000', 4) > 0 && blank($data['recovery_reason'] ?? null)) {
                $validator->errors()->add('recovery_reason', 'A reason is required when approving an OEM recovery value.');
            }
        })->validate();
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function componentAttributes(array $validated): array
    {
        return [
            'component_type' => $validated['component_type'], 'specification' => trim($validated['specification']),
            'capacity_value' => $validated['capacity_value'] ?? null, 'capacity_unit' => $validated['capacity_unit'] ?? null,
            'interface_type' => filled($validated['interface_type'] ?? null) ? trim($validated['interface_type']) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function recoveryAttributes(string $value, ?string $reason, User $actor): array
    {
        $approved = bccomp($value, '0.0000', 4) > 0;

        return [
            'approved_oem_recovery_value' => $value,
            'recovery_approved_by_user_id' => $approved ? $actor->id : null,
            'recovery_approved_at' => $approved ? now() : null,
            'recovery_reason' => $approved ? trim((string) $reason) : null,
        ];
    }

    private function authorizeRecovery(string $value, User $actor): void
    {
        if (bccomp($value, '0.0000', 4) > 0) {
            $this->authorization->authorize($actor, ComponentPermission::ApproveRecoveryValue);
        }
    }

    /** @param array<string, mixed> $data */
    private function recoveryValue(array $data): string
    {
        return number_format((float) ($data['approved_oem_recovery_value'] ?? 0), 4, '.', '');
    }

    private function recordRecoveryEvent(Component $component, string $old, string $new, string $reason, User $actor): void
    {
        ComponentRecoveryValueEvent::query()->create([
            'component_id' => $component->id, 'old_value' => $old, 'new_value' => $new,
            'reason' => trim($reason), 'actor_user_id' => $actor->id, 'recorded_at' => now(),
        ]);
    }
}
