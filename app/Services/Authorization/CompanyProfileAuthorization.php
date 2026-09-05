<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\CompanyProfilePermission;
use App\Enums\EmployeeRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CompanyProfileAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, CompanyProfilePermission $permission): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, CompanyProfilePermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, CompanyProfilePermission $permission): void
    {
        throw_unless($this->allows($user, $permission), AuthorizationException::class);
    }
}
