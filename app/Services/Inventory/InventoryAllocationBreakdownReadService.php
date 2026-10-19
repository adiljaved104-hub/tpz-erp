<?php

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/** Read-only warehouse-scoped projection of the existing allocation ledger. */
class InventoryAllocationBreakdownReadService
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly EmployeeOwnedInventoryReadService $ownership,
    ) {}

    public function canView(User $user, ProductInventory $inventory): bool
    {
        if (! $this->canViewLocation($user)) {
            return false;
        }

        return ! $this->ownership->isEmployeeView($user)
            || $this->ownership->canViewWarehouseAssignedInventory($user, (int) $inventory->getKey());
    }

    /**
     * Visible holders for a row. Warehouse-responsible employees and broad
     * inventory viewers receive all ledger holders; other employees receive
     * only their own recorded allocation.
     *
     * @return Collection<int, array{label:string,allocated:int,reserved:int,sellable:int,is_system:bool}>
     */
    public function stockHolderLines(User $user, ProductInventory $inventory): Collection
    {
        if (! $this->canViewLocation($user)) {
            return collect();
        }

        $balances = InventoryAllocationBalance::query();

        if ($this->ownership->isEmployeeView($user)
            && ! $this->ownership->canViewWarehouseAssignedInventory($user, (int) $inventory->getKey())) {
            $balances = $this->ownership->balances($user);
        }

        return $balances
            ->with(['account.employee', 'account.team'])
            ->where('product_inventory_id', $inventory->getKey())
            ->where(fn (Builder $query) => $query->where('allocated_quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
            ->orderBy('account_id')
            ->get()
            ->map(fn (InventoryAllocationBalance $balance): array => $this->line($balance))
            ->values();
    }

    /** Searchable account options limited to balances the current user may inspect. */
    public function stockHolderFilterOptions(User $user): array
    {
        if (! $this->canViewLocation($user)) {
            return [];
        }

        $balances = InventoryAllocationBalance::query()
            ->with(['account.employee', 'account.team'])
            ->where(fn (Builder $query) => $query->where('allocated_quantity', '>', 0)->orWhere('reserved_quantity', '>', 0));

        if ($this->ownership->isEmployeeView($user)) {
            $ownAccountId = $this->ownAccountId($user);
            $warehouseInventoryIds = $this->ownership->warehouseAssignedInventoryIds($user)
                ->select('product_inventories.id');
            $hasWarehouseAssignment = $this->ownership->hasActiveWarehouseAssignment($user);

            $balances->where(function (Builder $query) use ($hasWarehouseAssignment, $ownAccountId, $user, $warehouseInventoryIds): void {
                $query->whereIn('product_inventory_id', $warehouseInventoryIds);

                if (! $hasWarehouseAssignment && $ownAccountId !== null) {
                    $query->orWhere(fn (Builder $own) => $own
                        ->where('account_id', $ownAccountId)
                        ->whereIn('product_inventory_id', $this->ownership->inventoryIds($user)->select('product_inventory_id')));
                }
            });
        }

        $accountIds = $balances->select('account_id')->distinct()->pluck('account_id');

        return InventoryAllocationAccount::query()
            ->with(['employee', 'team'])
            ->whereIn('id', $accountIds)
            ->get()
            ->sortBy(fn (InventoryAllocationAccount $account): string => $this->accountLabelForAccount($account), SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (InventoryAllocationAccount $account): array => [
                (int) $account->id => $this->accountLabelForAccount($account),
            ])
            ->all();
    }

    /** Safe server-side record IDs for a holder filter, including crafted requests. */
    public function inventoryIdsForHolder(User $user, int $accountId): Builder
    {
        $query = ProductInventory::query()->select('product_inventories.id')
            ->whereExists(function (QueryBuilder $balances) use ($accountId): void {
                $balances->selectRaw('1')
                    ->from('inventory_allocation_balances as holder_balance')
                    ->whereColumn('holder_balance.product_inventory_id', 'product_inventories.id')
                    ->where('holder_balance.account_id', $accountId)
                    ->where(fn (QueryBuilder $quantity) => $quantity->where('holder_balance.allocated_quantity', '>', 0)
                        ->orWhere('holder_balance.reserved_quantity', '>', 0));
            });

        if (! $this->canViewLocation($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->ownership->isEmployeeView($user)) {
            $warehouseInventoryIds = $this->ownership->warehouseAssignedInventoryIds($user)
                ->select('product_inventories.id');
            $hasWarehouseAssignment = $this->ownership->hasActiveWarehouseAssignment($user);

            $query->where(function (Builder $scope) use ($accountId, $hasWarehouseAssignment, $user, $warehouseInventoryIds): void {
                $scope->whereIn('product_inventories.id', $warehouseInventoryIds);

                if (! $hasWarehouseAssignment && $accountId === $this->ownAccountId($user)) {
                    $scope->orWhereIn('product_inventories.id', $this->ownership->inventoryIds($user)->select('product_inventory_id'));
                }
            });
        }

        return $query;
    }

    /** @return array{total_stock:int,allocated:int,reserved:int,sellable:int,ledger_reserved:int,allocation_gap:int,reserved_gap:int,reconciled:bool,lines:Collection<int,array{label:string,allocated:int,reserved:int,sellable:int,is_system:bool}>}|null */
    public function forInventory(User $user, ProductInventory $inventory): ?array
    {
        if (! $this->canView($user, $inventory)) {
            return null;
        }

        $physical = ProductInventory::query()->whereKey($inventory->getKey())
            ->first(['id', 'available_quantity', 'reserved_quantity']);
        if ($physical === null) {
            return null;
        }

        $balances = InventoryAllocationBalance::query()
            ->with(['account.employee', 'account.team'])
            ->where('product_inventory_id', $physical->id)
            ->where(fn ($query) => $query->where('allocated_quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
            ->orderBy('account_id')
            ->get();

        $lines = $balances->map(fn (InventoryAllocationBalance $balance): array => [
            ...$this->line($balance),
        ]);

        if (! $lines->contains(fn (array $line): bool => $line['is_system'])) {
            $lines->push([
                'label' => 'System / Unallocated (no recorded ledger balance)',
                'allocated' => 0,
                'reserved' => 0,
                'sellable' => 0,
                'is_system' => true,
            ]);
        }

        $allocated = (int) $balances->sum('allocated_quantity');
        $ledgerReserved = (int) $balances->sum('reserved_quantity');
        $physicalTotal = (int) $physical->available_quantity;
        $physicalReserved = (int) $physical->reserved_quantity;

        return [
            'total_stock' => $physicalTotal,
            'allocated' => $allocated,
            'reserved' => $physicalReserved,
            'sellable' => $physicalTotal - $physicalReserved,
            'ledger_reserved' => $ledgerReserved,
            'allocation_gap' => $physicalTotal - $allocated,
            'reserved_gap' => $physicalReserved - $ledgerReserved,
            'reconciled' => $physicalTotal === $allocated && $physicalReserved === $ledgerReserved,
            'lines' => $lines->values(),
        ];
    }

    public function reconciliationText(User $user, ProductInventory $inventory): ?string
    {
        $data = $this->forInventory($user, $inventory);
        if ($data === null) {
            return null;
        }

        if ($data['reconciled']) {
            return "Reconciled · Total Stock {$data['total_stock']} = Allocated {$data['allocated']} · Reserved {$data['reserved']} · Sellable {$data['sellable']}";
        }

        return "Ledger discrepancy · physical {$data['total_stock']} / allocated {$data['allocated']} (gap {$data['allocation_gap']}); reserved {$data['reserved']} / ledger reserved {$data['ledger_reserved']} (gap {$data['reserved_gap']}). No allocation is inferred.";
    }

    public function linesText(User $user, ProductInventory $inventory): ?string
    {
        $data = $this->forInventory($user, $inventory);
        if ($data === null) {
            return null;
        }

        return $data['lines']->map(fn (array $line): string => "{$line['label']} — Allocated {$line['allocated']} · Reserved {$line['reserved']} · Sellable {$line['sellable']}")->implode("\n");
    }

    private function accountLabel(InventoryAllocationBalance $balance): string
    {
        $account = $balance->account;

        return $account === null ? 'Allocation account unavailable' : $this->accountLabelForAccount($account);
    }

    private function accountLabelForAccount(InventoryAllocationAccount $account): string
    {
        if ($account?->is_system) {
            return 'System / Unallocated';
        }

        if ($account?->employee !== null) {
            $employee = $account->employee;
            $label = trim(collect([$employee->employee_id, $employee->name])->filter()->implode(' · '));

            return $label.($employee->status ? '' : ' (Inactive)');
        }

        if ($account?->team !== null) {
            return 'Team · '.$account->team->name.($account->team->status ? '' : ' (Inactive)');
        }

        return $account->name ?? 'Allocation account unavailable';
    }

    private function line(InventoryAllocationBalance $balance): array
    {
        return [
            'label' => $this->accountLabel($balance),
            'allocated' => (int) $balance->allocated_quantity,
            'reserved' => (int) $balance->reserved_quantity,
            'sellable' => $balance->availableQuantity(),
            'is_system' => (bool) $balance->account?->is_system,
        ];
    }

    private function ownAccountId(User $user): ?int
    {
        if ($user->employee === null) {
            return null;
        }

        $id = InventoryAllocationAccount::query()
            ->where('type', 'employee')
            ->where('is_system', false)
            ->where('employee_id', $user->employee->id)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function canViewLocation(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::View)
            && $this->authorization->allows($user, InventoryPermission::ViewLocationBalances);
    }
}
