<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\DamagedStockPermission;
use App\Enums\EmployeeRole;
use App\Models\User;

class DamagedStockAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, DamagedStockPermission $permission): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, DamagedStockPermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }
}
