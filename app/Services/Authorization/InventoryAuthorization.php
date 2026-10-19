<?php

namespace App\Services\Authorization;

use App\Contracts\InventoryPermissionResolver;
use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\WarehouseStockActionScopeService;
use Illuminate\Auth\Access\AuthorizationException;

class InventoryAuthorization
{
    public function __construct(
        private readonly InventoryPermissionResolver $resolver,
        private readonly ResponsibilityProductScopeService $responsibilities,
        private readonly WarehouseStockActionScopeService $warehouseActions,
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

    /** Action grants never replace the corresponding Access Control permission. */
    public function authorizeWarehouseAction(User $user, InventoryPermission $permission, ProductInventory $inventory, string $capability): void
    {
        if ($user->employee?->status !== true
            || $user->employee->user_id !== $user->id
            || ! $this->resolver->allows($user, $permission, $inventory)
            || ! $this->warehouseActions->allows($user, $inventory, $capability)) {
            throw new AuthorizationException;
        }
    }
}
