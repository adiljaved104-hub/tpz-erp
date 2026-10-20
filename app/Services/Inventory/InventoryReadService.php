<?php

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\WarehouseStockActionScopeService;
use Illuminate\Database\Eloquent\Builder;

class InventoryReadService
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly ResponsibilityProductScopeService $responsibilities,
        private readonly WarehouseStockActionScopeService $warehouseActions,
    ) {}

    public function inventories(User $user): Builder
    {
        $query = $this->inventoryQuery($user);
        $this->responsibilities->applyInventories($query->getQuery(), 'product_inventories', $user);

        return $query;
    }

    /**
     * Stock Adjustment grid visibility preserves operational responsibilities
     * and adds only active, view-enabled adjustment grants when the actor also
     * has the separate inventory.adjust_stock permission.
     */
    public function adjustmentGridInventories(User $user): Builder
    {
        $query = $this->inventoryQuery($user);

        if (! $this->authorization->allows($user, InventoryPermission::AdjustStock)) {
            $this->responsibilities->applyInventories($query->getQuery(), 'product_inventories', $user);

            return $query;
        }

        $query->where(function (Builder $visible) use ($user): void {
            $visible->whereIn('product_inventories.id', $this->responsibilities->inventoryIds($user));
            $visible->orWhereIn('product_inventories.id', $this->warehouseActions->adjustmentInventoryIds($user));
        });

        return $query;
    }

    private function inventoryQuery(User $user): Builder
    {
        $fields = ['id', 'product_id', 'warehouse_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity', 'created_at', 'updated_at'];

        if ($this->authorization->allows($user, InventoryPermission::ViewFinancials)) {
            $fields[] = 'average_cost';
        }

        return ProductInventory::query()->select($fields)->with(['product:id,sku,name,status', 'warehouse:id,name,code,status']);
    }

    public function movements(User $user): Builder
    {
        $fields = [
            'id', 'reference', 'movement_group', 'product_inventory_id', 'product_id', 'warehouse_id',
            'movement_type', 'quantity', 'available_delta', 'reserved_delta', 'damaged_delta',
            'available_before', 'available_after', 'reserved_before', 'reserved_after',
            'damaged_before', 'damaged_after', 'source_type', 'source_id', 'reason',
            'actor_user_id', 'occurred_at', 'created_at',
        ];

        if ($this->authorization->allows($user, InventoryPermission::ViewFinancials)) {
            array_push($fields, 'unit_cost', 'average_cost_before', 'average_cost_after');
        }

        return StockMovement::query()->select($fields)
            ->whereIn('product_inventory_id', $this->responsibilities->inventoryIds($user))
            ->with(['product:id,sku,name,model', 'warehouse:id,name,code', 'actor:id,name,email']);
    }
}
