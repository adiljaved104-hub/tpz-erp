<?php

namespace App\Services\Authorization;

use App\Contracts\InventoryPermissionResolver;
use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Auth\Access\AuthorizationException;

class InventoryAuthorization
{
    public function __construct(
        private readonly InventoryPermissionResolver $resolver,
        private readonly ResponsibilityProductScopeService $responsibilities,
    ) {}

    public function allows(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        if (! $this->resolver->allows($user, $permission, $inventory)) {
            return false;
        }

        return $inventory === null || $this->responsibilities->canAccessInventory($user, (int) $inventory->getKey());
    }

    public function authorize(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): void
    {
        if (! $this->allows($user, $permission, $inventory)) {
            throw new AuthorizationException;
        }
    }
}
