<?php

namespace App\Services\Inventory;

use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only reporting over the existing allocation ledger. */
class StockByHolderReportService
{
    /** @param array{holder_id?: string, unassigned?: bool, search?: string, brand_id?: string, category_id?: string, warehouse_id?: string} $filters */
    public function balances(array $filters): Builder
    {
        $query = InventoryAllocationBalance::query()
            ->where('allocated_quantity', '>', 0)
            ->with(['account.employee', 'account.team', 'inventory.warehouse', 'inventory.product.brandRelation', 'inventory.product.categoryRelation']);

        if (filled($filters['holder_id'] ?? null)) {
            $query->where('account_id', (int) $filters['holder_id']);
        }
        if (($filters['unassigned'] ?? false) === true) {
            $query->whereHas('account', fn (Builder $account): Builder => $account->where('is_system', true));
        }
        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->whereHas('inventory.product', fn (Builder $product): Builder => $product
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%"));
        }
        if (filled($filters['brand_id'] ?? null)) {
            $query->whereHas('inventory.product', fn (Builder $product): Builder => $product->where('brand_id', (int) $filters['brand_id']));
        }
        if (filled($filters['category_id'] ?? null)) {
            $query->whereHas('inventory.product', fn (Builder $product): Builder => $product->where('category_id', (int) $filters['category_id']));
        }
        if (filled($filters['warehouse_id'] ?? null)) {
            $query->whereHas('inventory', fn (Builder $inventory): Builder => $inventory->where('warehouse_id', (int) $filters['warehouse_id']));
        }

        return $query;
    }

    /** @param array<string, mixed> $filters @return Collection<int, object> */
    public function holderSummaries(array $filters): Collection
    {
        $query = DB::table('inventory_allocation_balances as balances')
            ->join('inventory_allocation_accounts as accounts', 'accounts.id', '=', 'balances.account_id')
            ->join('product_inventories as inventories', 'inventories.id', '=', 'balances.product_inventory_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->leftJoin('employees', 'employees.id', '=', 'accounts.employee_id')
            ->leftJoin('teams', 'teams.id', '=', 'accounts.team_id')
            ->where('balances.allocated_quantity', '>', 0)
            ->select([
                'accounts.id as account_id', 'accounts.name as account_name', 'accounts.type as account_type',
                'accounts.is_system', 'employees.name as employee_name', 'employees.employee_id',
                'employees.status as employee_status', 'teams.name as team_name', 'teams.status as team_status',
            ])
            ->selectRaw('SUM(balances.allocated_quantity) as total_held')
            ->selectRaw('SUM(balances.reserved_quantity) as reserved_quantity')
            ->selectRaw('COUNT(DISTINCT inventories.product_id) as model_count');

        $this->applySummaryFilters($query, $filters);

        return $query->groupBy([
            'accounts.id', 'accounts.name', 'accounts.type', 'accounts.is_system',
            'employees.name', 'employees.employee_id', 'employees.status', 'teams.name', 'teams.status',
        ])->orderBy('accounts.is_system')->orderBy('accounts.name')->get();
    }

    /** @param array<string, mixed> $filters */
    public function distinctModelCount(array $filters): int
    {
        $query = DB::table('inventory_allocation_balances as balances')
            ->join('inventory_allocation_accounts as accounts', 'accounts.id', '=', 'balances.account_id')
            ->join('product_inventories as inventories', 'inventories.id', '=', 'balances.product_inventory_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->where('balances.allocated_quantity', '>', 0);
        $this->applySummaryFilters($query, $filters);

        return (int) $query->distinct()->count('inventories.product_id');
    }

    /** @return Collection<int, InventoryAllocationAccount> */
    public function holders(): Collection
    {
        return InventoryAllocationAccount::query()
            ->whereHas('balances', fn (Builder $balances): Builder => $balances->where('allocated_quantity', '>', 0))
            ->with(['employee', 'team'])->orderBy('is_system')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $filters */
    private function applySummaryFilters(\Illuminate\Database\Query\Builder $query, array $filters): void
    {
        $query->when(filled($filters['holder_id'] ?? null), fn ($builder) => $builder->where('accounts.id', (int) $filters['holder_id']));
        $query->when(($filters['unassigned'] ?? false) === true, fn ($builder) => $builder->where('accounts.is_system', true));
        $query->when(filled($filters['search'] ?? null), function ($builder) use ($filters): void {
            $search = '%'.trim((string) $filters['search']).'%';
            $builder->where(fn ($nested) => $nested->where('products.sku', 'like', $search)->orWhere('products.name', 'like', $search)->orWhere('products.model', 'like', $search));
        });
        $query->when(filled($filters['brand_id'] ?? null), fn ($builder) => $builder->where('products.brand_id', (int) $filters['brand_id']));
        $query->when(filled($filters['category_id'] ?? null), fn ($builder) => $builder->where('products.category_id', (int) $filters['category_id']));
        $query->when(filled($filters['warehouse_id'] ?? null), fn ($builder) => $builder->where('inventories.warehouse_id', (int) $filters['warehouse_id']));
    }
}
