<?php

namespace App\Services\Inventory;

use App\Enums\EmployeeRole;
use App\Models\InventoryAllocationBalance;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/** Read-only ownership projections. Never use these projected models to persist physical stock. */
class EmployeeOwnedInventoryReadService
{
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

    public function apply(QueryBuilder $query, string $inventoryColumn, User $user): void
    {
        if ($this->isEmployeeView($user)) {
            $query->whereIn($inventoryColumn, $this->inventoryIds($user));
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
            ->joinSub($this->balances($user)->select(['product_inventory_id', 'allocated_quantity', 'reserved_quantity'])
                ->where('allocated_quantity', '>', 0), 'owned_balance', 'owned_balance.product_inventory_id', '=', 'product_inventories.id')
            ->select(['product_inventories.id', 'product_id', 'warehouse_id', 'product_inventories.created_at', 'product_inventories.updated_at'])
            ->selectRaw('owned_balance.allocated_quantity AS available_quantity, owned_balance.reserved_quantity AS reserved_quantity')
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
