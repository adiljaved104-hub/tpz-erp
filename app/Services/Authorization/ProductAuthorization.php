<?php

namespace App\Services\Authorization;

use App\Contracts\ProductPermissionResolver;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ProductAuthorization
{
    public function __construct(private readonly ProductPermissionResolver $resolver) {}

    public function allows(User $user, ProductPermission $permission, ?Product $product = null): bool
    {
        $employee = $user->employee;

        if ($employee?->status !== true) {
            return false;
        }

        return $this->resolver->allows($user, $permission, $product);
    }

    public function authorize(User $user, ProductPermission $permission, ?Product $product = null): void
    {
        if (! $this->allows($user, $permission, $product)) {
            throw new AuthorizationException;
        }
    }
}
