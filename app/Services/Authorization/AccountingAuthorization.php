<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AccountingPermission;
use App\Enums\EmployeeRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AccountingAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function allows(User $user, AccountingPermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $ownerOnly = [
            AccountingPermission::ManagePeriods,
            AccountingPermission::PostJournal,
            AccountingPermission::ReverseJournal,
        ];
        $roleDefault = $user->employee->role === EmployeeRole::Owner
            || ($user->employee->role === EmployeeRole::Admin && ! in_array($permission, $ownerOnly, true));

        return $roleDefault || ($this->overrides->decision($user, $permission->value) ?? false);
    }

    public function authorize(User $user, AccountingPermission $permission): void
    {
        throw_unless($this->allows($user, $permission), AuthorizationException::class);
    }
}
