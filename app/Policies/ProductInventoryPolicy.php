<?php

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;

class ProductInventoryPolicy
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly ResponsibilityProductScopeService $responsibilities,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::View)
            && $this->authorization->allows($user, InventoryPermission::ViewLocationBalances);
    }

    public function view(User $user, ProductInventory $inventory): bool
    {
        return $this->viewAny($user)
            && $this->responsibilities->canAccessInventory($user, (int) $inventory->getKey());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProductInventory $inventory): bool
    {
        return false;
    }

    public function delete(User $user, ProductInventory $inventory): bool
    {
        return false;
    }

    public function restore(User $user, ProductInventory $inventory): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductInventory $inventory): bool
    {
        return false;
    }
}
