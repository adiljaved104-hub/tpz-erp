<?php

namespace App\Services\Inventory;

use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\ProductStatus;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only ownership projections. Never use these projected models to persist physical stock. */
class EmployeeOwnedInventoryReadService
{
    public function __construct(
        private readonly ResponsibilityProductScopeService $responsibilities,
        private readonly InventoryAuthorization $authorization,
    ) {}

    public function isEmployeeView(User $user): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Staff, EmployeeRole::Manager], true);
    }

    public function hasActiveWarehouseAssignment(User $user): bool
    {
        return $this->responsibilities->hasActiveWarehouseAssignment($user);
    }

    public function balances(User $user): Builder
    {
        return InventoryAllocationBalance::query()->whereHas('account', fn (Builder $account) => $account
            ->where('type', 'employee')->where('is_system', false)->where('employee_id', $user->employee?->id ?? 0));
    }

    public function inventoryIds(User $user): Builder
    {
        return $this->balances($user)->where('allocated_quantity', '>', 0)->select('product_inventory_id');
    }

    public function zeroStockProducts(): Builder
    {
        return Product::query()->products()->where('status', ProductStatus::Active)
            ->whereNotExists(fn (QueryBuilder $stock) => $stock->selectRaw('1')->from('product_inventories as company_stock')
                ->whereColumn('company_stock.product_id', 'products.id')
                ->whereColumn('company_stock.available_quantity', '>', 'company_stock.reserved_quantity'));
    }

    public function zeroStockInventoryIds(User $user): Builder
    {
        $query = ProductInventory::query()->select('product_inventories.id')
            ->whereIn('product_inventories.product_id', $this->zeroStockProducts()->select('products.id'));
        $this->responsibilities->applyInventories($query->getQuery(), 'product_inventories', $user);

        return $query;
    }

    public function noLocationProducts(User $user): Builder
    {
        $query = $this->zeroStockProducts()->whereDoesntHave('inventories');
        $this->responsibilities->applyNoLocationProducts($query->getQuery(), 'products.id', $user);

        return $query;
    }

    public function visibleInventoryIds(User $user): Builder
    {
        return ProductInventory::query()->select('product_inventories.id')->where(fn (Builder $query) => $query
            ->whereIn('product_inventories.id', $this->inventoryIds($user))
            ->orWhereIn('product_inventories.id', $this->zeroStockInventoryIds($user)));
    }

    /** Active, warehouse-specific employee visibility; used only by Stock by Location read paths. */
    public function warehouseAssignedInventoryIds(User $user): Builder
    {
        $query = ProductInventory::query()->select('product_inventories.id');

        if (! $this->isEmployeeView($user)
            || ! $this->authorization->allows($user, InventoryPermission::View)
            || ! $this->authorization->allows($user, InventoryPermission::ViewLocationBalances)) {
            return $query->whereRaw('1 = 0');
        }

        $this->responsibilities->applyWarehouseAssignedInventories($query->getQuery(), 'product_inventories', $user);

        return $query;
    }

    /** Existing employee ownership plus warehouse-authorized physical rows for the location screen. */
    public function locationVisibleInventoryIds(User $user): Builder
    {
        if ($this->hasActiveWarehouseAssignment($user)) {
            return $this->warehouseAssignedInventoryIds($user);
        }

        return ProductInventory::query()->select('product_inventories.id')->where(function (Builder $query) use ($user): void {
            $query->whereIn('product_inventories.id', $this->visibleInventoryIds($user))
                ->orWhereIn('product_inventories.id', $this->warehouseAssignedInventoryIds($user));
        });
    }

    public function canViewWarehouseAssignedInventory(User $user, int $inventoryId): bool
    {
        return $this->warehouseAssignedInventoryIds($user)
            ->where('product_inventories.id', $inventoryId)
            ->exists();
    }

    public function apply(QueryBuilder $query, string $inventoryColumn, User $user, ?string $productColumn = null): void
    {
        if ($this->isEmployeeView($user)) {
            $query->where(function (QueryBuilder $visible) use ($inventoryColumn, $productColumn, $user): void {
                $visible->whereIn($inventoryColumn, $this->visibleInventoryIds($user));
                if ($productColumn !== null) {
                    $visible->orWhere(fn (QueryBuilder $missing) => $missing->whereNull($inventoryColumn)
                        ->whereIn($productColumn, $this->noLocationProducts($user)->select('products.id')));
                }
            });
        }
    }

    public function metrics(User $user, Collection $inventoryIds): Collection
    {
        return $this->balances($user)->whereIn('product_inventory_id', $inventoryIds)->get()->keyBy('product_inventory_id');
    }

    public function inventories(User $user): Builder
    {
        if (! $this->isEmployeeView($user)) {
            return app(InventoryReadService::class)->inventories($user);
        }

        return ProductInventory::query()
            ->leftJoinSub($this->balances($user)->select(['product_inventory_id', 'allocated_quantity', 'reserved_quantity'])
                ->where('allocated_quantity', '>', 0), 'owned_balance', 'owned_balance.product_inventory_id', '=', 'product_inventories.id')
            ->select(['product_inventories.id', 'product_id', 'warehouse_id', 'product_inventories.created_at', 'product_inventories.updated_at'])
            ->whereIn('product_inventories.id', $this->visibleInventoryIds($user))
            ->selectRaw('COALESCE(owned_balance.allocated_quantity, 0) AS available_quantity, COALESCE(owned_balance.reserved_quantity, 0) AS reserved_quantity')
            ->selectRaw('CASE WHEN owned_balance.product_inventory_id IS NULL THEN 1 ELSE 0 END AS responsibility_zero_stock')
            ->with(['product:id,sku,name,status', 'warehouse:id,name,code,status']);
    }

    /**
     * Stock by Location query. Employees see their normal ledger projection, except
     * active assigned warehouses where physical quantity and ledger totals are shown.
     */
    public function locationInventories(User $user): Builder
    {
        if (! $this->isEmployeeView($user)) {
            return app(InventoryReadService::class)->inventories($user)
                ->withSum('allocationBalances as allocated_quantity', 'allocated_quantity')
                ->withSum('allocationBalances as allocation_reserved_quantity', 'reserved_quantity');
        }

        if (! $this->authorization->allows($user, InventoryPermission::View)
            || ! $this->authorization->allows($user, InventoryPermission::ViewLocationBalances)) {
            return ProductInventory::query()->whereRaw('1 = 0');
        }

        $visibleInventoryIds = ProductInventory::query()->select('product_inventories.id')
            ->where(function (Builder $visible) use ($user): void {
                if ($this->hasActiveWarehouseAssignment($user)) {
                    $visible->whereIn('product_inventories.id', $this->warehouseAssignedInventoryIds($user));

                    return;
                }

                $visible->whereIn('product_inventories.id', $this->visibleInventoryIds($user))
                    ->orWhereIn('product_inventories.id', $this->warehouseAssignedInventoryIds($user));
            });

        $ownedBalances = $this->balances($user)
            ->select(['product_inventory_id', 'allocated_quantity', 'reserved_quantity'])
            ->where('allocated_quantity', '>', 0);
        $warehouseIds = $this->warehouseAssignedInventoryIds($user)
            ->reorder()
            ->selectRaw('product_inventories.id as inventory_id')
            ->getQuery();
        $allocationTotals = DB::table('inventory_allocation_balances')
            ->select('product_inventory_id')
            ->selectRaw('SUM(allocated_quantity) as allocated_quantity')
            ->selectRaw('SUM(reserved_quantity) as reserved_quantity')
            ->groupBy('product_inventory_id');

        $projection = DB::table('product_inventories')
            ->leftJoinSub($ownedBalances, 'owned_balance', 'owned_balance.product_inventory_id', '=', 'product_inventories.id')
            ->leftJoinSub($warehouseIds, 'warehouse_scope_inventory', 'warehouse_scope_inventory.inventory_id', '=', 'product_inventories.id')
            ->leftJoinSub($allocationTotals, 'allocation_totals', 'allocation_totals.product_inventory_id', '=', 'product_inventories.id')
            ->select([
                'product_inventories.id', 'product_inventories.product_id', 'product_inventories.warehouse_id',
                'product_inventories.created_at', 'product_inventories.updated_at',
            ])
            ->selectRaw('CASE WHEN warehouse_scope_inventory.inventory_id IS NOT NULL THEN product_inventories.available_quantity ELSE COALESCE(owned_balance.allocated_quantity, 0) END AS available_quantity')
            ->selectRaw('CASE WHEN warehouse_scope_inventory.inventory_id IS NOT NULL THEN product_inventories.reserved_quantity ELSE COALESCE(owned_balance.reserved_quantity, 0) END AS reserved_quantity')
            ->selectRaw('CASE WHEN warehouse_scope_inventory.inventory_id IS NOT NULL THEN COALESCE(allocation_totals.allocated_quantity, 0) ELSE COALESCE(owned_balance.allocated_quantity, 0) END AS allocated_quantity')
            ->selectRaw('CASE WHEN warehouse_scope_inventory.inventory_id IS NOT NULL THEN COALESCE(allocation_totals.reserved_quantity, 0) ELSE COALESCE(owned_balance.reserved_quantity, 0) END AS allocation_reserved_quantity')
            ->selectRaw('CASE WHEN warehouse_scope_inventory.inventory_id IS NOT NULL THEN 1 ELSE 0 END AS warehouse_responsibility_visible')
            ->selectRaw('CASE WHEN warehouse_scope_inventory.inventory_id IS NULL AND owned_balance.product_inventory_id IS NULL THEN 1 ELSE 0 END AS responsibility_zero_stock');

        return ProductInventory::query()
            ->fromSub($projection, 'product_inventories')
            ->select('product_inventories.*')
            ->whereIn('product_inventories.id', $visibleInventoryIds)
            ->with(['product:id,sku,name,status', 'warehouse:id,name,code,status']);
    }

    public function quantityColumn(User $user, string $column, bool $locationView = false): string
    {
        if (! $this->isEmployeeView($user)) {
            return 'product_inventories.'.$column;
        }

        if ($locationView) {
            return 'product_inventories.'.$column;
        }

        return 'owned_balance.'.($column === 'available_quantity' ? 'allocated_quantity' : $column);
    }
}
