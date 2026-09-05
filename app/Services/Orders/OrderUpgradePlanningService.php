<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\OrderUpgradePlan;
use App\Enums\ProductStatus;
use App\Enums\RecoveryValuationMethod;
use App\Enums\UpgradeRecipeOperation;
use App\Models\Component;
use App\Models\OrderItem;
use App\Models\OrderItemUpgradeSelection;
use App\Models\ProductHardwareProfile;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\UpgradeRecipeLine;
use App\Models\User;
use App\Services\Upgrades\UpgradeRecipeService;
use App\Services\Upgrades\UpgradeRecipeValidationService;
use Illuminate\Validation\ValidationException;

class OrderUpgradePlanningService
{
    public function __construct(
        private readonly UpgradeRecipeValidationService $validator,
        private readonly UpgradeRecipeService $recipes,
    ) {}

    public function plan(OrderItemData $item): ?OrderUpgradePlan
    {
        if ($item->salesConfigurationId === null && $item->upgradeRecipeId === null) {
            return null;
        }
        if ($item->salesConfigurationId === null || $item->upgradeRecipeId === null) {
            throw ValidationException::withMessages(['items' => 'Select both a valid Sales Configuration and Build Method.']);
        }

        $configuration = SalesConfiguration::query()
            ->with('product.hardwareProfile')
            ->find($item->salesConfigurationId);
        $recipe = UpgradeRecipe::query()
            ->with(['salesConfiguration.product.hardwareProfile', 'lines.installComponent.product', 'lines.recoveredComponent.product'])
            ->find($item->upgradeRecipeId);

        $this->assertSelection($item, $configuration, $recipe);

        $lines = $recipe->lines->map(fn (UpgradeRecipeLine $line): array => $this->snapshotLine($line, $item->quantity))->all();
        $recovery = collect($lines)
            ->filter(fn (array $line): bool => in_array($line['operation'], ['remove_and_return', 'remove_as_damaged'], true))
            ->map(fn (array $line): array => [
                'upgrade_recipe_line_id' => $line['upgrade_recipe_line_id'],
                'operation' => $line['operation'],
                'component_id' => $line['component_id'],
                'source' => $line['recovery_source'],
                'approved_unit_value' => $line['approved_recovery_unit_value'],
                'quantity' => $line['quantity'],
            ])->values()->all();

        return new OrderUpgradePlan(
            productId: $item->productId,
            salesConfigurationId: $configuration->id,
            upgradeRecipeId: $recipe->id,
            hardwareProfileVersion: $configuration->hardware_profile_version,
            configurationSnapshot: [
                'display_name' => $configuration->display_name,
                'target_ram_mb' => $configuration->target_ram_mb,
                'target_storage_total_gb' => $configuration->target_storage_total_gb,
                'target_storage_layout' => $configuration->target_storage_layout,
            ],
            recipeSnapshot: [
                'name' => $recipe->name,
                'preferred' => $recipe->preferred,
                'hardware_profile_version' => $recipe->hardware_profile_version,
                'lines' => $lines,
            ],
            suggestedSellingAddon: (string) $configuration->suggested_selling_addon,
            labourUnitCost: (string) $recipe->labour_unit_cost,
            recoverySnapshot: ['lines' => $recovery],
            lines: $lines,
        );
    }

    public function lockAndCreateSelection(OrderItem $item, OrderUpgradePlan $plan, User $actor): OrderItemUpgradeSelection
    {
        $profile = ProductHardwareProfile::query()->where('product_id', $item->product_id)->lockForUpdate()->first();
        $configuration = SalesConfiguration::query()->lockForUpdate()->find($plan->salesConfigurationId);
        $recipe = UpgradeRecipe::query()->with(['salesConfiguration.product.hardwareProfile', 'lines.installComponent.product', 'lines.recoveredComponent.product'])
            ->lockForUpdate()->find($plan->upgradeRecipeId);

        if ($profile === null || $profile->profile_version !== $plan->hardwareProfileVersion) {
            throw ValidationException::withMessages(['items' => 'The Product Hardware Profile changed. Select the upgraded configuration again.']);
        }
        $this->assertSelection(
            new OrderItemData($item->product_id, $item->ordered_quantity, (string) $item->selling_price, salesConfigurationId: $plan->salesConfigurationId, upgradeRecipeId: $plan->upgradeRecipeId),
            $configuration,
            $recipe,
        );

        return OrderItemUpgradeSelection::query()->create([
            'order_item_id' => $item->id,
            'sales_configuration_id' => $plan->salesConfigurationId,
            'upgrade_recipe_id' => $plan->upgradeRecipeId,
            'hardware_profile_version' => $plan->hardwareProfileVersion,
            'configuration_snapshot' => $plan->configurationSnapshot,
            'recipe_snapshot' => $plan->recipeSnapshot,
            'suggested_selling_addon_snapshot' => $plan->suggestedSellingAddon,
            'labour_cost_snapshot' => $plan->labourUnitCost,
            'recovery_snapshot' => $plan->recoverySnapshot,
            'selected_by_user_id' => $actor->id,
        ]);
    }

    public function planFromSelection(OrderItemUpgradeSelection $selection): OrderUpgradePlan
    {
        return new OrderUpgradePlan(
            productId: $selection->orderItem->product_id,
            salesConfigurationId: $selection->sales_configuration_id,
            upgradeRecipeId: $selection->upgrade_recipe_id,
            hardwareProfileVersion: $selection->hardware_profile_version,
            configurationSnapshot: $selection->configuration_snapshot,
            recipeSnapshot: $selection->recipe_snapshot,
            suggestedSellingAddon: (string) $selection->suggested_selling_addon_snapshot,
            labourUnitCost: (string) $selection->labour_cost_snapshot,
            recoverySnapshot: $selection->recovery_snapshot,
            lines: array_values($selection->recipe_snapshot['lines'] ?? []),
        );
    }

    public function assertSelectionCurrent(OrderItemUpgradeSelection $selection): void
    {
        $selection->loadMissing('orderItem');
        $configuration = SalesConfiguration::query()->with('product.hardwareProfile')->lockForUpdate()->find($selection->sales_configuration_id);
        $recipe = UpgradeRecipe::query()->with(['salesConfiguration.product.hardwareProfile', 'lines.installComponent.product', 'lines.recoveredComponent.product'])
            ->lockForUpdate()->find($selection->upgrade_recipe_id);
        $this->assertSelection(
            new OrderItemData(
                $selection->orderItem->product_id,
                $selection->orderItem->ordered_quantity,
                (string) $selection->orderItem->selling_price,
                salesConfigurationId: $selection->sales_configuration_id,
                upgradeRecipeId: $selection->upgrade_recipe_id,
            ),
            $configuration,
            $recipe,
        );
        if ($selection->hardware_profile_version !== $configuration?->hardware_profile_version) {
            throw ValidationException::withMessages(['items' => 'The saved upgraded configuration is stale and cannot be reserved.']);
        }
    }

    /** @param list<OrderItemData> $items @return array<int, OrderUpgradePlan|null> */
    public function plans(array $items): array
    {
        return array_map(fn (OrderItemData $item): ?OrderUpgradePlan => $this->plan($item), $items);
    }

    private function assertSelection(OrderItemData $item, ?SalesConfiguration $configuration, ?UpgradeRecipe $recipe): void
    {
        if ($configuration === null || $configuration->product_id !== $item->productId || ! $configuration->active) {
            throw ValidationException::withMessages(['items' => 'Select an active Sales Configuration for the chosen Product.']);
        }
        $profile = $configuration->product?->hardwareProfile;
        if ($profile === null) {
            throw ValidationException::withMessages(['items' => 'Hardware profile incomplete. Configure the Product Hardware Profile first.']);
        }
        if ($configuration->hardware_profile_version !== $profile->profile_version) {
            throw ValidationException::withMessages(['items' => 'The selected Sales Configuration is stale. Select a current configuration.']);
        }
        if ($recipe === null || $recipe->sales_configuration_id !== $configuration->id || ! $recipe->active || $recipe->hardware_profile_version !== $profile->profile_version) {
            throw ValidationException::withMessages(['items' => 'Select an active Build Method for the current Hardware Profile.']);
        }
        $result = $this->validator->validate($recipe);
        if (! $result->valid) {
            throw ValidationException::withMessages(['items' => 'No valid upgrade recipe. '.implode(' ', $result->errors)]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshotLine(UpgradeRecipeLine $line, int $orderQuantity): array
    {
        $quantity = bcmul((string) $line->quantity_per_laptop, (string) $orderQuantity, 4);
        if (! preg_match('/^\d+\.0000$/', $quantity) || (int) $quantity < 1) {
            throw ValidationException::withMessages(['items' => "Recipe line {$line->sequence} must resolve to a whole inventory quantity."]);
        }

        $operation = $line->operation;
        $component = $operation === UpgradeRecipeOperation::Install ? $line->installComponent : $line->recoveredComponent;
        $recoverySource = null;
        $approvedRecovery = '0.0000';
        if (in_array($operation, [UpgradeRecipeOperation::RemoveAndReturn, UpgradeRecipeOperation::RemoveAsDamaged], true)) {
            if ($line->recovery_valuation_method === RecoveryValuationMethod::Override) {
                $recoverySource = RecoveryValuationMethod::Override->value;
                $approvedRecovery = (string) ($line->recovery_value_override ?? '0.0000');
            } else {
                $recoverySource = RecoveryValuationMethod::CentralApproved->value;
                $approvedRecovery = $line->recoveredComponent === null
                    ? '0.0000'
                    : (string) ($line->recoveredComponent->approved_oem_recovery_value ?? '0.0000');
            }
        }

        return [
            'upgrade_recipe_line_id' => $line->id,
            'sequence' => $line->sequence,
            'operation' => $operation->value,
            'source_slot_key' => $line->source_slot_key,
            'target_slot_key' => $line->target_slot_key,
            'component_id' => $component?->id,
            'component_product_id' => $component?->product_id,
            'component' => $this->componentSnapshot($component),
            'quantity_per_laptop' => (string) $line->quantity_per_laptop,
            'quantity' => (int) $quantity,
            'recovery_source' => $recoverySource,
            'approved_recovery_unit_value' => $approvedRecovery,
        ];
    }

    /** @return array<string, mixed>|null */
    private function componentSnapshot(?Component $component): ?array
    {
        if ($component === null) {
            return null;
        }
        if ($component->product === null || $component->product->status !== ProductStatus::Active) {
            throw ValidationException::withMessages(['items' => 'An upgrade Component is unavailable.']);
        }

        return [
            'component_id' => $component->id,
            'sku' => $component->product?->sku,
            'type' => $component->component_type?->value,
            'specification' => $component->specification,
            'capacity_value' => $component->capacity_value,
            'capacity_unit' => $component->capacity_unit,
            'interface_type' => $component->interface_type,
        ];
    }
}
