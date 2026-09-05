<?php

namespace App\Contracts;

use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;

interface InventoryPermissionResolver
{
    public function allows(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): bool;
}
