<?php

namespace App\Services\Security;

use App\Enums\EmployeeRole;
use App\Models\User;

final class MfaPolicy
{
    /** @var list<EmployeeRole> */
    private const array REQUIRED_ROLES = [
        EmployeeRole::Owner,
        EmployeeRole::Admin,
        EmployeeRole::Manager,
    ];

    public function requires(User $user): bool
    {
        return in_array($user->employee?->role, self::REQUIRED_ROLES, true);
    }
}
