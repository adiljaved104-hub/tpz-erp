<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\BackupSettingsPermission;
use App\Enums\EmployeeRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class BackupSettingsAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, BackupSettingsPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => $permission !== BackupSettingsPermission::Download,
            default => false,
        };
    }

    public function allows(User $user, BackupSettingsPermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, BackupSettingsPermission $permission): void
    {
        if (! $this->allows($user, $permission)) {
            throw new AuthorizationException;
        }
    }
}
