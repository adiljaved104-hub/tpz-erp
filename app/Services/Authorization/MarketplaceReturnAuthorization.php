<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\MarketplaceReturnPermission;
use App\Models\CustomerReturnItem;
use App\Models\MarketplaceReturnRemoval;
use App\Models\User;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceReturnAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides, private readonly OrderResponsibilityScopeService $scope) {}

    public function roleDefault(User $user, MarketplaceReturnPermission $permission): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function allows(User $user, MarketplaceReturnPermission $permission, ?MarketplaceReturnRemoval $removal = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }$default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);
        if (! $allowed) {
            return false;
        }

        return $removal === null || $removal->items()->get()->every(fn ($item): bool => $this->scope->canAccessProduct(
            $user,
            $item->product_id,
            $removal->marketplace_platform_id,
            $removal->source_warehouse_id,
        ));
    }

    public function allowsReturnItem(User $user, MarketplaceReturnPermission $permission, CustomerReturnItem $item): bool
    {
        return $this->allows($user, $permission)
            && $this->scope->canAccessOrder($user, $item->customerReturn()->with('order')->firstOrFail()->order);
    }

    public function scopeQuery(Builder $query, User $user): Builder
    {
        if (! $this->scope->requiresScope($user)) {
            return $query;
        }

        return $this->scope->applyMarketplaceRemovals($query, $user);
    }

    public function authorize(User $u, MarketplaceReturnPermission $p, ?MarketplaceReturnRemoval $r = null): void
    {
        if (! $this->allows($u, $p, $r)) {
            throw new AuthorizationException;
        }
    }

    public function authorizeReturnItem(User $user, MarketplaceReturnPermission $permission, CustomerReturnItem $item): void
    {
        if (! $this->allowsReturnItem($user, $permission, $item)) {
            throw new AuthorizationException;
        }
    }
}
