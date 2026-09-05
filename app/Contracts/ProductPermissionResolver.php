<?php

namespace App\Contracts;

use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;

interface ProductPermissionResolver
{
    public function allows(User $user, ProductPermission $permission, ?Product $product = null): bool;
}
