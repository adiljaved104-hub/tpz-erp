<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\ExpensePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ExpenseAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, ExpensePermission $permission): bool
    {
        return $user->employee?->role === EmployeeRole::Owner;
    }

    public function allows(User $user, ExpensePermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, ExpensePermission $permission): void
    {
        throw_unless($this->allows($user, $permission), AuthorizationException::class);
    }
}
