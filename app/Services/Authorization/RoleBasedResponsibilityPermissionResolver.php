<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\ResponsibilityPermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;

class RoleBasedResponsibilityPermissionResolver implements ResponsibilityPermissionResolver
{
    public function __construct(private readonly ?EmployeePermissionOverrideResolver $overrides = null) {}

    public function allows(User $user, ResponsibilityPermission $permission, ?ResponsibilityAssignment $assignment = null): bool
    {
        $roleDefault = $this->roleDefault($user, $permission, $assignment);

        if ($user->employee?->role === EmployeeRole::Owner) {
            return $roleDefault;
        }

        return $this->overrides?->decision($user, $permission->value) ?? $roleDefault;
    }

    public function roleDefault(User $user, ResponsibilityPermission $permission, ?ResponsibilityAssignment $assignment = null): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => in_array($permission, [
                ResponsibilityPermission::ViewOwn,
                ResponsibilityPermission::ViewTeam,
                ResponsibilityPermission::ViewHistory,
            ], true),
            EmployeeRole::Staff => $permission === ResponsibilityPermission::ViewOwn,
            default => false,
        };
    }
}
