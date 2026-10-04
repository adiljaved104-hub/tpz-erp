<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\MarketplaceOperationsPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class MarketplaceOperationsAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, MarketplaceOperationsPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => $permission === MarketplaceOperationsPermission::View,
            default => false,
        };
    }

    public function allows(User $user, MarketplaceOperationsPermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, MarketplaceOperationsPermission $permission): void
    {
        if (! $this->allows($user, $permission)) {
            throw new AuthorizationException;
        }
    }
}
