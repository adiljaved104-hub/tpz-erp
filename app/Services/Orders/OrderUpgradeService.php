<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderUpgradePlan;
use App\Enums\InventoryReservationKind;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductStatus;
use App\Enums\UpgradeRecipeOperation;
use App\Exceptions\InventoryInvariantException;
use App\Models\Component;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\OrderItemUpgradeSelection;
use App\Models\OrderUpgradeExecution;
use App\Models\OrderUpgradeExecutionLine;
use App\Models\ProductInventory;
use App\Models\UpgradeRecipeLine;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderUpgradeService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<int, OrderUpgradePlan|null> $plans */
    public function assertInstallStock(array $plans, int $warehouseId): void
    {
        $requirements = collect($plans)->filter()->flatMap(fn (OrderUpgradePlan $plan): array => $plan->installLines())
            ->groupBy('component_product_id')
            ->map(fn ($lines): int => (int) $lines->sum('quantity'));
        if ($requirements->isEmpty()) {
            return;
        }

        $components = Component::query()->with('product')
            ->whereIn('product_id', $requirements->keys()->map(fn ($id): int => (int) $id))
            ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
        $inventories = ProductInventory::query()->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $requirements->keys()->map(fn ($id): int => (int) $id))
            ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

        foreach ($requirements as $productId => $required) {
            $component = $components->get((int) $productId);
            $available = $inventories->get((int) $productId)?->sellableQuantity() ?? 0;
            if ($component?->product?->status !== ProductStatus::Active || $available < $required) {
                $label = $component?->specification ?? 'Required upgrade Component';
                throw ValidationException::withMessages([
                    'items' => "Cannot reserve upgraded configuration. {$label} required: {$required}; available: {$available}.",
                ]);
            }
        }
    }

    /**
     * @param  array<int, string>  $reservationReferences  keyed by recipe-line ID
     * @param  array<int, string>  $movementReferences  keyed by recipe-line ID
     * @param  array<int, string>  $postingKeys  keyed by recipe-line ID
     */
    public function reserveComponents(
        OrderItemUpgradeSelection $selection,
        OrderUpgradePlan $plan,
        User $actor,
        array $reservationReferences,
        array $movementReferences,
        array $postingKeys,
        string $movementGroup,
    ): void {
        foreach ($plan->installLines() as $line) {
            $lineId = (int) $line['upgrade_recipe_line_id'];
            $recipeLine = UpgradeRecipeLine::query()->lockForUpdate()->findOrFail($lineId);
            if ($recipeLine->upgrade_recipe_id !== $selection->upgrade_recipe_id || $recipeLine->install_component_id !== $line['component_id']) {
                throw new InventoryInvariantException('The selected upgrade recipe changed before component reservation.');
            }
            $this->inventory->reserveUpgradeComponent(
                $selection,
                $recipeLine,
                (int) $line['quantity'],
                $actor,
                $reservationReferences[$lineId],
                $movementReferences[$lineId],
                $postingKeys[$lineId],
                $movementGroup,
            );
        }
    }

    public function lockFulfillmentInventories(Order $order): void
    {
        $order->loadMissing('items.upgradeSelection');
        $productIds = $order->items->pluck('product_id');
        foreach ($order->items as $item) {
            foreach (($item->upgradeSelection?->recipe_snapshot['lines'] ?? []) as $line) {
                if (filled($line['component_product_id'] ?? null)) {
                    $productIds->push((int) $line['component_product_id']);
                }
            }
        }
        ProductInventory::query()->where('warehouse_id', $order->warehouse_id)
            ->whereIn('product_id', $productIds->unique()->sort()->values())
            ->orderBy('product_id')->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  array<int, string>  $movementReferences  keyed by recipe-line ID
     * @param  array<int, string>  $postingKeys  keyed by recipe-line ID
     */
    public function execute(
        OrderItemUpgradeSelection $selection,
        OrderFulfillmentItem $fulfillmentItem,
        User $actor,
        string $idempotencyKey,
        array $movementReferences,
        array $postingKeys,
        bool $direct,
    ): OrderUpgradeExecution {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Order upgrade execution requires an active transaction.');
        }
        if ($existing = OrderUpgradeExecution::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $selection = OrderItemUpgradeSelection::query()
            ->with(['orderItem.order.fulfillment', 'componentReservations'])
            ->lockForUpdate()->findOrFail($selection->id);
        if ($existing = OrderUpgradeExecution::query()->where('order_item_upgrade_selection_id', $selection->id)->first()) {
            return $existing;
        }
        if ($selection->order_item_id !== $fulfillmentItem->order_item_id) {
            throw new InventoryInvariantException('Upgrade selection and fulfilment line do not match.');
        }

        $lines = collect($selection->recipe_snapshot['lines'] ?? [])->sortBy('sequence')->values();
        $reservations = $selection->componentReservations->keyBy('upgrade_recipe_line_id');
        $baseCogs = (string) $fulfillmentItem->cogs_total;
        $installedCost = '0.0000';

        foreach ($lines->where('operation', UpgradeRecipeOperation::Install->value) as $line) {
            $inventory = ProductInventory::query()->where('product_id', (int) $line['component_product_id'])
                ->where('warehouse_id', $fulfillmentItem->warehouse_id)->lockForUpdate()->first();
            if ($inventory === null || $inventory->average_cost === null) {
                throw ValidationException::withMessages(['items' => 'An installed Component has no available costed inventory.']);
            }
            $quantity = (int) $line['quantity'];
            if ($direct) {
                if ($inventory->sellableQuantity() < $quantity) {
                    throw ValidationException::withMessages(['items' => 'An upgrade Component is no longer available.']);
                }
            } else {
                $reservation = $reservations->get((int) $line['upgrade_recipe_line_id']);
                if (! $reservation instanceof InventoryReservation
                    || $reservation->reservation_kind !== InventoryReservationKind::UpgradeComponent
                    || $reservation->status !== InventoryReservationStatus::Active
                    || $reservation->quantity !== $quantity
                    || $reservation->product_inventory_id !== $inventory->id) {
                    throw new InventoryInvariantException('Every installed Component must have its exact active reservation.');
                }
            }
            $installedCost = bcadd($installedCost, bcmul((string) $quantity, (string) $inventory->average_cost, 4), 4);
        }

        $recoveryPlans = [];
        $recoveryCredit = '0.0000';
        $remainingRecovery = $baseCogs;
        foreach ($lines->filter(fn (array $line): bool => in_array($line['operation'], [UpgradeRecipeOperation::RemoveAndReturn->value, UpgradeRecipeOperation::RemoveAsDamaged->value], true)) as $line) {
            $quantity = (int) $line['quantity'];
            $approvedUnit = (string) ($line['approved_recovery_unit_value'] ?? '0.0000');
            $approvedTotal = bcmul((string) $quantity, $approvedUnit, 4);
            $appliedTotal = bccomp($approvedTotal, $remainingRecovery, 4) > 0 ? $remainingRecovery : $approvedTotal;
            $appliedUnit = bcdiv($appliedTotal, (string) $quantity, 4);
            $appliedTotal = bcmul($appliedUnit, (string) $quantity, 4);
            $remainingRecovery = bcsub($remainingRecovery, $appliedTotal, 4);
            $recoveryCredit = bcadd($recoveryCredit, $appliedTotal, 4);
            $recoveryPlans[(int) $line['upgrade_recipe_line_id']] = [
                'approved_unit' => $approvedUnit,
                'applied_unit' => $appliedUnit,
                'applied_total' => $appliedTotal,
            ];
        }

        $labourCost = bcmul((string) $selection->orderItem->ordered_quantity, (string) $selection->labour_cost_snapshot, 4);
        $finalCogs = bcsub(bcadd(bcadd($baseCogs, $installedCost, 4), $labourCost, 4), $recoveryCredit, 4);
        if (bccomp($finalCogs, '0', 4) < 0) {
            $finalCogs = '0.0000';
        }

        try {
            $execution = OrderUpgradeExecution::query()->create([
                'order_item_upgrade_selection_id' => $selection->id,
                'order_fulfillment_item_id' => $fulfillmentItem->id,
                'idempotency_key' => $idempotencyKey,
                'base_cogs' => $baseCogs,
                'installed_component_cost' => $installedCost,
                'recovery_credit' => $recoveryCredit,
                'labour_cost' => $labourCost,
                'final_configured_cogs' => $finalCogs,
                'executed_by_user_id' => $actor->id,
                'executed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($existing = OrderUpgradeExecution::query()->where('order_item_upgrade_selection_id', $selection->id)->first()) {
                return $existing;
            }
            throw $exception;
        }

        foreach ($lines as $line) {
            $lineId = (int) $line['upgrade_recipe_line_id'];
            $operation = UpgradeRecipeOperation::from($line['operation']);
            $movement = null;
            $inventory = null;
            $actualUnitCost = '0.0000';
            $totalValue = '0.0000';
            $recoveryPlan = $recoveryPlans[$lineId] ?? null;

            if ($operation === UpgradeRecipeOperation::Install) {
                $result = $direct
                    ? $this->inventory->consumeUpgradeComponentDirect((int) $line['component_product_id'], $fulfillmentItem->warehouse_id, (int) $line['quantity'], $execution, $actor, $movementReferences[$lineId], $postingKeys[$lineId])
                    : $this->inventory->fulfillUpgradeComponentReservation($reservations->get($lineId), $execution, $actor, $movementReferences[$lineId], $postingKeys[$lineId]);
                $movement = $result['movement'];
                $inventory = $result['inventory'];
                $actualUnitCost = $result['unit_cost'];
                $totalValue = $result['total_value'];
            } elseif (in_array($operation, [UpgradeRecipeOperation::RemoveAndReturn, UpgradeRecipeOperation::RemoveAsDamaged], true)) {
                $component = Component::query()->lockForUpdate()->findOrFail((int) $line['component_id']);
                $result = $this->inventory->recoverUpgradeComponent(
                    $component, $fulfillmentItem->warehouse_id, (int) $line['quantity'], $recoveryPlan['applied_unit'],
                    $operation === UpgradeRecipeOperation::RemoveAsDamaged, $execution, $actor,
                    $movementReferences[$lineId], $postingKeys[$lineId],
                );
                $movement = $result['movement'];
                $inventory = $result['inventory'];
                $actualUnitCost = $recoveryPlan['applied_unit'];
                $totalValue = $result['total_value'];
            }

            OrderUpgradeExecutionLine::query()->create([
                'order_upgrade_execution_id' => $execution->id,
                'upgrade_recipe_line_id' => $lineId,
                'operation' => $operation,
                'component_id' => $line['component_id'],
                'product_inventory_id' => $inventory?->id,
                'slot_snapshot' => ['source' => $line['source_slot_key'], 'target' => $line['target_slot_key']],
                'component_specification_snapshot' => $line['component'],
                'quantity' => (int) $line['quantity'],
                'actual_unit_cost' => $actualUnitCost,
                'total_value' => $totalValue,
                'recovery_source_snapshot' => $line['recovery_source'],
                'recovery_approved_unit_value_snapshot' => $recoveryPlan['approved_unit'] ?? null,
                'recovery_applied_unit_value_snapshot' => $recoveryPlan['applied_unit'] ?? null,
                'stock_movement_id' => $movement?->id,
                'created_at' => now(),
            ]);
        }

        $this->activity->log('order.upgrade_executed', $actor, $execution, [
            'order_id' => $selection->orderItem->order_id,
            'order_item_id' => $selection->order_item_id,
            'configuration_id' => $selection->sales_configuration_id,
            'recipe_id' => $selection->upgrade_recipe_id,
        ]);

        return $execution->load('lines');
    }
}
