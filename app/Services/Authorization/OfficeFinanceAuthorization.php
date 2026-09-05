<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\OfficeFinancePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class OfficeFinanceAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, OfficeFinancePermission $permission): bool
    {
        return $user->employee?->role === EmployeeRole::Owner;
    }

    public function allows(User $user, OfficeFinancePermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, OfficeFinancePermission $permission): void
    {
        throw_unless($this->allows($user, $permission), AuthorizationException::class);
    }
}
