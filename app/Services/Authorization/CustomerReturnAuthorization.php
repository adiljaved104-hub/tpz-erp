<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Auth\Access\AuthorizationException;

class CustomerReturnAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides, private readonly OrderResponsibilityScopeService $scope) {}

    public function roleDefault(User $user, CustomerReturnPermission $permission): bool
    {
        if (in_array($permission, [CustomerReturnPermission::ViewRefundAmount, CustomerReturnPermission::RecordRefund], true)) {
            return $user->employee?->role === EmployeeRole::Owner;
        }

        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => in_array($permission, [CustomerReturnPermission::View, CustomerReturnPermission::Create], true),
            default => false,
        };
    }

    public function allows(User $user, CustomerReturnPermission $permission, ?CustomerReturn $return = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
        if (! $allowed) {
            return false;
        }

        return $return === null || $this->scope->canAccessOrder($user, $return->order);
    }

    public function authorize(User $user, CustomerReturnPermission $permission, ?CustomerReturn $return = null): void
    {
        if (! $this->allows($user, $permission, $return)) {
            throw new AuthorizationException;
        }
    }
}
