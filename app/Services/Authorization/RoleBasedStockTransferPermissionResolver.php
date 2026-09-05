<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\StockTransferPermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\StockTransferPermission;
use App\Models\User;

class RoleBasedStockTransferPermissionResolver implements StockTransferPermissionResolver
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function allows(User $user, StockTransferPermission $permission): bool
    {
        if ($user->employee === null || ! $user->employee->status) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);
        if ($user->employee->role === EmployeeRole::Owner) {
            return $default;
        }

        return $this->overrides->decision($user, $permission->value) ?? $default;
    }

    public function roleDefault(User $user, StockTransferPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => $permission !== StockTransferPermission::ViewCost,
            EmployeeRole::Manager => in_array($permission, [StockTransferPermission::View, StockTransferPermission::Create], true),
            default => false,
        };
    }
}
