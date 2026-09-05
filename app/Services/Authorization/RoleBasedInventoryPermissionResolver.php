<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\InventoryPermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;

class RoleBasedInventoryPermissionResolver implements InventoryPermissionResolver
{
    public function __construct(private readonly ?EmployeePermissionOverrideResolver $overrides = null) {}

    public function allows(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): bool
    {
        $roleDefault = $this->roleDefault($user, $permission, $inventory);

        if ($user->employee?->role === EmployeeRole::Owner) {
            return $roleDefault;
        }

        return $this->overrides?->decision($user, $permission->value) ?? $roleDefault;
    }

    public function roleDefault(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => $permission !== InventoryPermission::ReverseOpeningStock,
            EmployeeRole::Admin => in_array($permission, [
                InventoryPermission::View,
                InventoryPermission::Reserve,
                InventoryPermission::ReleaseReservation,
                InventoryPermission::MarkDamaged,
                InventoryPermission::RestoreDamaged,
                InventoryPermission::ViewMovements,
            ], true),
            EmployeeRole::Manager => in_array($permission, [
                InventoryPermission::View,
                InventoryPermission::ViewMovements,
            ], true),
            default => false,
        };
    }
}
