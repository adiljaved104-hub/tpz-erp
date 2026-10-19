<?php

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Models\InventoryAllocationBalance;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
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
        if (! $this->authorization->allows($user, InventoryPermission::View)
            || ! $this->authorization->allows($user, InventoryPermission::ViewLocationBalances)) {
            return false;
        }

        return ! $this->ownership->isEmployeeView($user)
            || $this->ownership->canViewWarehouseAssignedInventory($user, (int) $inventory->getKey());
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
            'label' => $this->accountLabel($balance),
            'allocated' => (int) $balance->allocated_quantity,
            'reserved' => (int) $balance->reserved_quantity,
            'sellable' => $balance->availableQuantity(),
            'is_system' => (bool) $balance->account?->is_system,
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

        return $account?->name ?? 'Allocation account unavailable';
    }
}
