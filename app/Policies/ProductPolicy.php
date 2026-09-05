<?php

namespace App\Policies;

use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;

class ProductPolicy
{
    public function __construct(private readonly ProductAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, ProductPermission::View);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->authorization->allows($user, ProductPermission::View, $product);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, ProductPermission::Create);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->authorization->allows($user, ProductPermission::Update, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return false;
    }

    public function restore(User $user, Product $product): bool
    {
        return false;
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }
}
