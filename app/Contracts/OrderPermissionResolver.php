<?php

namespace App\Contracts;

use App\Enums\OrderPermission;
use App\Models\Order;
use App\Models\User;

interface OrderPermissionResolver
{
    public function allows(User $user, OrderPermission $permission, ?Order $order = null): bool;
}
