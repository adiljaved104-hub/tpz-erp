<?php

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;

class ProductInventoryPolicy
{
    public function __construct(private readonly InventoryAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::View);
    }

    public function view(User $user, ProductInventory $inventory): bool
    {
        return $this->authorization->allows($user, InventoryPermission::View, $inventory);
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
