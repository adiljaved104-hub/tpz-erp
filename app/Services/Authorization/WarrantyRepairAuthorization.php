<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairPermission;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class WarrantyRepairAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides, private readonly OrderResponsibilityScopeService $scope) {}

    public function roleDefault(User $user, WarrantyRepairPermission $permission): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, WarrantyRepairPermission $permission, ?WarrantyRepair $case = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }$default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);

        return $allowed && ($case === null || $this->scope->canAccessProduct($user, $case->product_id, $case->marketplace_platform_id, $case->warehouse_id));
    }

    public function authorize(User $user, WarrantyRepairPermission $permission, ?WarrantyRepair $case = null): void
    {
        if (! $this->allows($user, $permission, $case)) {
            throw new AuthorizationException;
        }
    }

    public function scopeQuery(Builder $query, User $user): Builder
    {
        if (! $this->scope->requiresScope($user)) {
            return $query;
        }

        return $this->scope->applyWarrantyCases($query, $user);
    }
}
