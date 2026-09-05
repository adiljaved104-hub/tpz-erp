<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\UpgradePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UpgradeAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, UpgradePermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => $permission !== UpgradePermission::ApproveRecoveryOverride,
            default => false,
        };
    }

    public function allows(User $user, UpgradePermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, UpgradePermission $permission): void
    {
        throw_unless($this->allows($user, $permission), AuthorizationException::class);
    }
}
