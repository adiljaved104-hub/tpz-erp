<?php

namespace App\Services\Inventory;

use App\Enums\EmployeeRole;
use App\Enums\ProductStatus;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/** Read-only ownership projections. Never use these projected models to persist physical stock. */
class EmployeeOwnedInventoryReadService
{
    public function __construct(private readonly ResponsibilityProductScopeService $responsibilities) {}

    public function isEmployeeView(User $user): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Staff, EmployeeRole::Manager], true);
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

    public function quantityColumn(User $user, string $column): string
    {
        if (! $this->isEmployeeView($user)) {
            return 'product_inventories.'.$column;
        }

        return 'owned_balance.'.($column === 'available_quantity' ? 'allocated_quantity' : $column);
    }
}
