<?php

namespace App\Contracts;

use App\Enums\PurchasePermission;
use App\Models\Purchase;
use App\Models\User;

interface PurchasePermissionResolver
{
    public function allows(User $user, PurchasePermission $permission, ?Purchase $purchase = null): bool;
}
