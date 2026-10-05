<?php

namespace App\Services\Authorization;

use App\Contracts\ProductPermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ProductAuthorization
{
    public function __construct(private readonly ProductPermissionResolver $resolver) {}

    public function allows(User $user, ProductPermission $permission, ?Product $product = null): bool
    {
        $employee = $user->employee;

        if ($employee?->status !== true) {
            return false;
        }

        return $this->resolver->allows($user, $permission, $product)
            && ($product === null || ! $this->requiresCreatorScope($user) || $product->created_by_user_id === $user->id);
    }

    public function requiresCreatorScope(User $user): bool
    {
        return $user->employee?->role === EmployeeRole::Staff;
    }

    /** Products module queries only; operational catalog selectors must not use this scope. */
    public function applyCreatorScope(Builder|QueryBuilder $query, User $user): void
    {
        if ($this->requiresCreatorScope($user)) {
            $query->where('products.created_by_user_id', $user->id);
        }
    }

    public function authorize(User $user, ProductPermission $permission, ?Product $product = null): void
    {
        if (! $this->allows($user, $permission, $product)) {
            throw new AuthorizationException;
        }
    }
}
