<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\ComplaintPermission;
use App\Enums\EmployeeRole;
use App\Models\Complaint;
use App\Models\User;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class ComplaintAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides, private readonly OrderResponsibilityScopeService $scope) {}

    public function roleDefault(User $user, ComplaintPermission $permission): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, ComplaintPermission $permission, ?Complaint $case = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }$default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);
        if (! $allowed) {
            return false;
        }
        if (! $this->scope->requiresScope($user)) {
            return true;
        }
        if ($case === null || $case->product_id === null) {
            return true;
        }
        $warehouse = $case->order?->warehouse_id ?? $case->customerReturn?->receiving_warehouse_id;

        return $warehouse !== null && $this->scope->canAccessProduct($user, $case->product_id, $case->marketplace_platform_id, $warehouse);
    }

    public function authorize(User $user, ComplaintPermission $permission, ?Complaint $case = null): void
    {
        if (! $this->allows($user, $permission, $case)) {
            throw new AuthorizationException;
        }
    }

    public function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scope->applyComplaintCases($query, $user);
    }
}
