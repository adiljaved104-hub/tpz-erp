<?php

namespace App\Services\Inventory;

use App\Enums\WarrantyRepairStatus;
use App\Models\DamagedStockEvent;
use App\Models\ProductInventory;
use App\Models\WarrantyRepair;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DamagedStockAvailabilityService
{
    public function remaining(DamagedStockEvent $damage): int
    {
        if ($damage->status->value !== 'damaged') {
            return 0;
        }

        return max(0, $damage->quantity - $this->resolvedQuantity($damage->id));
    }

    public function availableForRepair(DamagedStockEvent $damage): int
    {
        return max(0, $this->remaining($damage) - $this->activeRepairQuantity($damage->id));
    }

    public function resolvedQuantity(int $damageId): int
    {
        return (int) WarrantyRepair::query()
            ->where('damaged_stock_event_id', $damageId)
            ->whereNotNull('repair_qc_movement_id')
            ->sum('quantity');
    }

    public function activeRepairQuantity(int $damageId): int
    {
        return (int) WarrantyRepair::query()
            ->where('damaged_stock_event_id', $damageId)
            ->whereNotIn('status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])
            ->sum('quantity');
    }

    public function resolvedQuantitiesQuery(): Builder
    {
        return DB::table('warranty_repairs')
            ->whereNotNull('damaged_stock_event_id')
            ->whereNotNull('repair_qc_movement_id')
            ->selectRaw('damaged_stock_event_id, SUM(quantity) AS resolved_quantity')
            ->groupBy('damaged_stock_event_id');
    }

    public function activeRepairQuantitiesQuery(): Builder
    {
        return DB::table('warranty_repairs')
            ->whereNotNull('damaged_stock_event_id')
            ->whereNotIn('status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])
            ->selectRaw('damaged_stock_event_id, SUM(quantity) AS active_repair_quantity')
            ->groupBy('damaged_stock_event_id');
    }

    public function eventAvailabilityQuery(): Builder
    {
        return DB::table('damaged_stock_events as damage')
            ->leftJoinSub($this->resolvedQuantitiesQuery(), 'resolved_damage', 'resolved_damage.damaged_stock_event_id', '=', 'damage.id')
            ->leftJoinSub($this->activeRepairQuantitiesQuery(), 'active_damage_repair', 'active_damage_repair.damaged_stock_event_id', '=', 'damage.id')
            ->select('damage.*')
            ->selectRaw("CASE WHEN damage.status = 'damaged' AND damage.quantity > COALESCE(resolved_damage.resolved_quantity, 0) THEN damage.quantity - COALESCE(resolved_damage.resolved_quantity, 0) ELSE 0 END AS remaining_damaged_quantity")
            ->selectRaw("CASE WHEN damage.status = 'damaged' AND damage.quantity > COALESCE(resolved_damage.resolved_quantity, 0) + COALESCE(active_damage_repair.active_repair_quantity, 0) THEN damage.quantity - COALESCE(resolved_damage.resolved_quantity, 0) - COALESCE(active_damage_repair.active_repair_quantity, 0) ELSE 0 END AS available_repair_quantity");
    }

    public function legacyAvailableForRepair(ProductInventory $inventory): int
    {
        return max(0, $inventory->damaged_quantity
            - $this->structuredRemainingForInventory($inventory->id)
            - $this->activeLegacyRepairQuantity($inventory->id));
    }

    public function structuredRemainingForInventory(int $inventoryId): int
    {
        return (int) DB::query()->fromSub($this->eventAvailabilityQuery(), 'structured_damage')
            ->where('product_inventory_id', $inventoryId)
            ->sum('remaining_damaged_quantity');
    }

    public function activeLegacyRepairQuantity(int $inventoryId): int
    {
        return (int) WarrantyRepair::query()
            ->where('product_inventory_id', $inventoryId)
            ->where('source', 'damaged_item')
            ->whereNull('damaged_stock_event_id')
            ->whereNotIn('status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])
            ->sum('quantity');
    }

    public function activeLegacyRepairQuantitiesQuery(): Builder
    {
        return DB::table('warranty_repairs')
            ->where('source', 'damaged_item')
            ->whereNull('damaged_stock_event_id')
            ->whereNotNull('product_inventory_id')
            ->whereNotIn('status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])
            ->selectRaw('product_inventory_id, SUM(quantity) AS active_legacy_repair_quantity')
            ->groupBy('product_inventory_id');
    }
}
