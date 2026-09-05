<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\SafetClaimPermission;
use App\Models\SafetClaim;
use App\Models\User;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class SafetClaimAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides, private readonly OrderResponsibilityScopeService $scope) {}

    public function roleDefault(User $user, SafetClaimPermission $permission): bool
    {
        if (in_array($permission, [SafetClaimPermission::ViewFinancial, SafetClaimPermission::UpdateFinancial], true)) {
            return $user->employee?->role === EmployeeRole::Owner;
        }

        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, SafetClaimPermission $permission, ?SafetClaim $claim = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);
        if (! $allowed) {
            return false;
        }

        return $claim === null || $this->scope->canAccessProduct($user, $claim->product_id, $claim->marketplace_platform_id, $claim->damagedStockEvent->warehouse_id);
    }

    public function scopeQuery(Builder $query, User $user): Builder
    {
        if (! $this->scope->requiresScope($user)) {
            return $query;
        }

        return $this->scope->applySafetClaims($query, $user);
    }

    public function authorize(User $user, SafetClaimPermission $permission, ?SafetClaim $claim = null): void
    {
        if (! $this->allows($user, $permission, $claim)) {
            throw new AuthorizationException;
        }
    }
}
