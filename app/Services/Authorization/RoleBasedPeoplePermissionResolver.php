<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\PeoplePermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\PeoplePermission;
use App\Models\User;

class RoleBasedPeoplePermissionResolver implements PeoplePermissionResolver
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function allows(User $user, PeoplePermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        if ($user->employee->role === EmployeeRole::Owner) {
            return $default;
        }

        return $this->overrides->decision($user, $permission->value) ?? $default;
    }

    public function roleDefault(User $user, PeoplePermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => ! in_array($permission, [
                PeoplePermission::EmployeeChangeRole,
                PeoplePermission::EmployeeUnlinkUser,
            ], true),
            EmployeeRole::Manager => in_array($permission, [
                PeoplePermission::EmployeeView,
                PeoplePermission::TeamView,
            ], true),
            default => false,
        };
    }
}
