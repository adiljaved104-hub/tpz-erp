<?php

namespace App\Services\Authorization;

use App\Contracts\CatalogPermissionResolver;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\CatalogPermission;
use App\Enums\EmployeeRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RoleBasedCatalogPermissionResolver implements CatalogPermissionResolver
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function allows(User $user, CatalogPermission $permission, ?Model $record = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        if ($user->employee->role === EmployeeRole::Owner) {
            return $default;
        }

        return $this->overrides->decision($user, $permission->value) ?? $default;
    }

    public function roleDefault(User $user, CatalogPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => in_array($permission, [
                CatalogPermission::BrandView,
                CatalogPermission::CategoryView,
            ], true),
            default => false,
        };
    }
}
