<?php

namespace App\Contracts;

use App\Enums\InventoryLocationPermission;
use App\Models\User;

interface InventoryLocationPermissionResolver
{
    public function allows(User $user, InventoryLocationPermission $permission): bool;
}
