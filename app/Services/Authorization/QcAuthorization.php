<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\QcPermission;
use App\Models\QcInspection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class QcAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, QcPermission $permission): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, QcPermission $permission, ?QcInspection $inspection = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);
        if (! $allowed || ($permission !== QcPermission::View && ! $this->allows($user, QcPermission::View))) {
            return false;
        }

        return $inspection === null || $inspection->technician_user_id === $user->id || $this->allows($user, QcPermission::ViewAll);
    }

    public function authorize(User $user, QcPermission $permission, ?QcInspection $inspection = null): void
    {
        throw_unless($this->allows($user, $permission, $inspection), AuthorizationException::class);
    }
}
