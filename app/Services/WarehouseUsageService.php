<?php

namespace App\Services;

use App\DTOs\Usage\UsageCheckResult;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehouseUsageService
{
    public function check(Warehouse $warehouse): UsageCheckResult
    {
        $sources = [];

        if (Schema::hasTable('product_inventories') && DB::table('product_inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where(function ($query): void {
                $query->where('available_quantity', '>', 0)
                    ->orWhere('reserved_quantity', '>', 0)
                    ->orWhere('damaged_quantity', '>', 0)
                    ->orWhere('marketplace_non_sellable_quantity', '>', 0)
                    ->orWhere('qc_pending_quantity', '>', 0);
            })->exists()) {
            $sources[] = 'nonzero_inventory';
        }

        if (Schema::hasTable('inventory_reservations') && DB::table('inventory_reservations')
            ->where('warehouse_id', $warehouse->id)
            ->where('status', 'active')
            ->exists()) {
            $sources[] = 'active_reservations';
        }

        if (Schema::hasTable('purchases') && DB::table('purchases')->where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['draft', 'approved', 'partially_received'])->exists()) {
            $sources[] = 'open_purchases';
        }

        if (Schema::hasTable('orders') && DB::table('orders')->where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['draft', 'pending_review', 'confirmed', 'reserved', 'processing'])->exists()) {
            $sources[] = 'open_orders';
        }

        if (Schema::hasTable('stock_transfers') && DB::table('stock_transfers')
            ->where(fn ($query) => $query->where('source_warehouse_id', $warehouse->id)->orWhere('destination_warehouse_id', $warehouse->id))
            ->whereIn('status', ['draft', 'dispatched'])->exists()) {
            $sources[] = 'open_stock_transfers';
        }

        return $sources === [] ? UsageCheckResult::unused() : new UsageCheckResult(true, $sources);
    }
}
