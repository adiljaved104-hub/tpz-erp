<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\InventoryLocationPermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Models\User;

class RoleBasedInventoryLocationPermissionResolver implements InventoryLocationPermissionResolver
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function allows(User $user, InventoryLocationPermission $permission): bool
    {
        $employee = $user->employee;

        if ($employee === null || ! $employee->status) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        if ($employee->role === EmployeeRole::Owner) {
            return $default;
        }

        return $this->overrides->decision($user, $permission->value) ?? $default;
    }

    public function roleDefault(User $user, InventoryLocationPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => $permission === InventoryLocationPermission::View,
            default => false,
        };
    }
}
