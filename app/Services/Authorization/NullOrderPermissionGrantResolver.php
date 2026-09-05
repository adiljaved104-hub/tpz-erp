<?php

namespace App\Services\Authorization;

use App\Contracts\OrderPermissionGrantResolver;
use App\Enums\OrderPermission;
use App\Models\Order;
use App\Models\User;

class NullOrderPermissionGrantResolver implements OrderPermissionGrantResolver
{
    public function decision(User $user, OrderPermission $permission, ?Order $order = null): ?bool
    {
        return null;
    }
}
