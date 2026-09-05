<?php

namespace App\Services\Upgrades;

use App\Enums\InventoryItemType;
use App\Enums\ProductPermission;
use App\Enums\UpgradePermission;
use App\Models\Product;
use App\Models\SalesConfiguration;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\UpgradeAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesConfigurationService
{
    public function __construct(
        private readonly UpgradeAuthorization $authorization,
        private readonly ProductAuthorization $products,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): SalesConfiguration
    {
        $this->authorization->authorize($actor, UpgradePermission::ManageConfigurations);
        $validated = $this->validate($data);
        $this->authorizeSellingFields($data, $actor);

        return DB::transaction(function () use ($validated, $actor): SalesConfiguration {
            $product = Product::query()->products()->lockForUpdate()->with('hardwareProfile')->findOrFail($validated['product_id']);
            throw_if($product->inventory_item_type !== InventoryItemType::Product, ValidationException::withMessages(['product_id' => 'Sales Configurations require a normal Product.']));
            throw_if($product->hardwareProfile === null, ValidationException::withMessages(['product_id' => 'Create a Hardware Profile before creating Sales Configurations.']));

            $configuration = SalesConfiguration::query()->create([
                ...$this->attributes($validated),
                'hardware_profile_version' => $product->hardwareProfile->profile_version,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->activity->log('sales_configuration.created', $actor, $configuration);

            return $configuration->load('product.hardwareProfile');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SalesConfiguration $configuration, array $data, User $actor): SalesConfiguration
    {
        $this->authorization->authorize($actor, UpgradePermission::ManageConfigurations);
        $data['product_id'] = $configuration->product_id;
        $validated = $this->validate($data, $configuration);
        $this->authorizeSellingFields($data, $actor, $configuration);

        return DB::transaction(function () use ($configuration, $validated, $actor): SalesConfiguration {
            $configuration = SalesConfiguration::query()->lockForUpdate()->with('product.hardwareProfile')->findOrFail($configuration->id);
            throw_if($configuration->isStale(), ValidationException::withMessages(['display_name' => 'This configuration is stale. Revalidate or rebuild it against the current Hardware Profile first.']));
            $configuration->forceFill([...$this->attributes($validated), 'updated_by_user_id' => $actor->id])->save();
            $this->activity->log('sales_configuration.updated', $actor, $configuration);

            return $configuration->refresh()->load('product.hardwareProfile');
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validate(array $data, ?SalesConfiguration $configuration = null): array
    {
        $profileVersion = $configuration?->hardware_profile_version;
        if ($profileVersion === null && filled($data['product_id'] ?? null)) {
            $profileVersion = Product::query()
                ->with('hardwareProfile:id,product_id,profile_version')
                ->find($data['product_id'])
                ?->hardwareProfile
                ?->profile_version;
        }

        return Validator::make($data, [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('inventory_item_type', InventoryItemType::Product->value)],
            'display_name' => ['required', 'string', 'max:255', Rule::unique('sales_configurations', 'display_name')->where(fn ($query) => $query->where('product_id', $data['product_id'] ?? null)->where('hardware_profile_version', $profileVersion))->ignore($configuration?->id)],
            'target_ram_mb' => ['nullable', 'integer', 'min:1'],
            'target_storage_total_gb' => ['nullable', 'numeric', 'gt:0'],
            'target_storage_layout' => ['nullable', 'array'],
            'target_storage_layout.*.slot_key' => ['required_with:target_storage_layout', 'string', 'max:64'],
            'target_storage_layout.*.capacity_gb' => ['required_with:target_storage_layout', 'numeric', 'gt:0'],
            'target_storage_layout.*.interface' => ['nullable', 'string', 'max:120'],
            'suggested_selling_addon' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price' => ['nullable', 'numeric', 'min:0'],
            'active' => ['required', 'boolean'],
        ])->after(function ($validator) use ($data): void {
            if (blank($data['target_ram_mb'] ?? null) && blank($data['target_storage_total_gb'] ?? null) && blank($data['target_storage_layout'] ?? null)) {
                $validator->errors()->add('target_ram_mb', 'At least one RAM or storage target is required.');
            }
        })->validate();
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function attributes(array $validated): array
    {
        return [
            'product_id' => $validated['product_id'],
            'display_name' => trim($validated['display_name']),
            'target_ram_mb' => $validated['target_ram_mb'] ?? null,
            'target_storage_total_gb' => $validated['target_storage_total_gb'] ?? null,
            'target_storage_layout' => $validated['target_storage_layout'] ?? null,
            'suggested_selling_addon' => $validated['suggested_selling_addon'] ?? 0,
            'default_selling_price' => $validated['default_selling_price'] ?? null,
            'active' => $validated['active'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function authorizeSellingFields(array $data, User $actor, ?SalesConfiguration $configuration = null): void
    {
        $changed = array_key_exists('suggested_selling_addon', $data) || array_key_exists('default_selling_price', $data);
        if ($configuration !== null) {
            $changed = (array_key_exists('suggested_selling_addon', $data) && bccomp((string) ($data['suggested_selling_addon'] ?? 0), (string) $configuration->suggested_selling_addon, 2) !== 0)
                || (array_key_exists('default_selling_price', $data) && (string) ($data['default_selling_price'] ?? '') !== (string) ($configuration->default_selling_price ?? ''));
        }
        if ($changed) {
            $this->authorization->authorize($actor, UpgradePermission::ManageSellingAddons);
            $this->products->authorize($actor, ProductPermission::EditSellingPrice, $configuration?->product);
        }
    }
}
