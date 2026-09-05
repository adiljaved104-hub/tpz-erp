<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\NotificationRulePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class NotificationRuleAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, NotificationRulePermission $permission): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, NotificationRulePermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, NotificationRulePermission $permission): void
    {
        if (! $this->allows($user, $permission)) {
            throw new AuthorizationException;
        }
    }
}
