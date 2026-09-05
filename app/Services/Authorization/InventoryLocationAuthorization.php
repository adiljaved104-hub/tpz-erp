<?php

namespace App\Services\Authorization;

use App\Contracts\InventoryLocationPermissionResolver;
use App\Enums\InventoryLocationPermission;
use App\Models\User;

class InventoryLocationAuthorization
{
    public function __construct(private readonly InventoryLocationPermissionResolver $resolver) {}

    public function allows(User $user, InventoryLocationPermission $permission): bool
    {
        return $this->resolver->allows($user, $permission);
    }
}
