<?php

namespace App\Services\Notifications;

use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\InventoryAllocationBalance;
use App\Models\ProductInventory;
use App\Models\SafetClaim;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Support\Collection;

class CriticalAlertRecipientResolver
{
    public function __construct(
        private readonly NotificationRuleService $rules,
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly OrderResponsibilityScopeService $orderResponsibilities,
    ) {}

    /** @return Collection<int, User> */
    public function warranty(WarrantyRepair $case, string $eventKey = 'warranty.due_soon'): Collection
    {
        $strategy = $this->rules->recipientStrategy($eventKey);
        if ($strategy === 'owner_admin_fallback') {
            return $this->authorizedAdmins(fn (User $user): bool => $this->warrantyAuthorized($user, $case));
        }
        if ($case->assignedTo instanceof User && $this->warrantyAuthorized($case->assignedTo, $case)) {
            return collect([$case->assignedTo]);
        }

        return $strategy === 'assigned_employee'
            ? collect()
            : $this->authorizedAdmins(fn (User $user): bool => $this->warrantyAuthorized($user, $case));
    }

    /** @return Collection<int, User> */
    public function inventory(ProductInventory $inventory, string $eventKey = 'inventory.low_stock'): Collection
    {
        return $this->activeUsers()
            ->filter(fn (User $user): bool => in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
                || $this->inventoryAlertAuthorized($user, $inventory))
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    public function inventoryEscalation(ProductInventory $inventory): Collection
    {
        return $this->authorizedAdmins(fn (User $user): bool => $this->inventoryAlertAuthorized($user, $inventory));
    }

    public function inventoryAlertAuthorized(User $user, ProductInventory $inventory): bool
    {
        if (in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            return true;
        }

        if (! $this->inventoryAuthorization->allows($user, InventoryPermission::View)) {
            return false;
        }

        if ($this->hasPositiveAllocation($user, $inventory)) {
            return true;
        }

        if ($this->inventoryAuthorization->allows($user, InventoryPermission::View, $inventory)) {
            return true;
        }

        if (! in_array($user->employee?->role, [EmployeeRole::Manager, EmployeeRole::Staff], true)) {
            return false;
        }

        $inventory->loadMissing('product.marketplaceListings');

        return $inventory->product->marketplaceListings
            ->pluck('marketplace_platform_id')
            ->map(fn ($platformId): int => (int) $platformId)
            ->unique()
            ->contains(fn (int $platformId): bool => $this->orderResponsibilities->hasMatchingActiveResponsibility(
                $user,
                (int) $inventory->product_id,
                $platformId,
                (int) $inventory->warehouse_id,
            ));
    }

    private function hasPositiveAllocation(User $user, ProductInventory $inventory): bool
    {
        $employee = $user->employee;
        if ($employee === null) {
            return false;
        }

        return InventoryAllocationBalance::query()
            ->join('inventory_allocation_accounts as account', 'account.id', '=', 'inventory_allocation_balances.account_id')
            ->where('inventory_allocation_balances.product_inventory_id', $inventory->id)
            ->where('inventory_allocation_balances.allocated_quantity', '>', 0)
            ->where('account.status', true)
            ->where('account.is_system', false)
            ->where(function ($query) use ($employee): void {
                $query->where('account.employee_id', $employee->id);
                if ($employee->team_id !== null) {
                    $query->orWhere('account.team_id', $employee->team_id);
                }
            })
            ->exists();
    }

    /** @return Collection<int, User> */
    public function claim(SafetClaim $claim): Collection
    {
        $strategy = $this->rules->recipientStrategy('claim.needs_filing');
        if ($strategy === 'owner_admin_fallback') {
            return $this->authorizedAdmins(fn (User $user): bool => app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::View, $claim));
        }
        if ($claim->assignedTo instanceof User && app(SafetClaimAuthorization::class)->allows($claim->assignedTo, SafetClaimPermission::View, $claim)) {
            return collect([$claim->assignedTo]);
        }

        return $strategy === 'assigned_employee'
            ? collect()
            : $this->authorizedAdmins(fn (User $user): bool => app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::View, $claim));
    }

    /** @return Collection<int, User> */
    public function customerReturn(CustomerReturn $return): Collection
    {
        if ($this->rules->recipientStrategy('return.awaiting_qc') === 'owner_admin_fallback') {
            return $this->authorizedAdmins(fn (User $user): bool => app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View, $return));
        }

        $authorized = $this->activeUsers()->filter(fn (User $user): bool => app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::Inspect, $return))->values();

        return $authorized->isNotEmpty() ? $authorized : $this->authorizedAdmins(fn (User $user): bool => app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View, $return));
    }

    private function warrantyAuthorized(User $user, WarrantyRepair $case): bool
    {
        return app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View, $case);
    }

    /** @return Collection<int, User> */
    private function authorizedAdmins(callable $allows): Collection
    {
        return $this->activeUsers()
            ->filter(fn (User $user): bool => in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true) && $allows($user))
            ->values();
    }

    /** @return Collection<int, User> */
    private function activeUsers(): Collection
    {
        return Employee::query()->where('status', true)->whereNotNull('user_id')->with('user')->get()
            ->pluck('user')->filter(fn ($user): bool => $user instanceof User)->unique('id')->values();
    }
}
