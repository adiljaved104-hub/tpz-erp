<?php

namespace App\Services\Inventory;

use App\Models\ProductInventory;
use Illuminate\Support\Facades\DB;

class InventoryBalanceService
{
    public function lockOrCreate(int $productId, int $warehouseId): ProductInventory
    {
        DB::table('product_inventories')->insertOrIgnore([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ProductInventory::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lock(int $inventoryId): ProductInventory
    {
        return ProductInventory::query()->lockForUpdate()->findOrFail($inventoryId);
    }
}
