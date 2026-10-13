<?php

namespace App\Services\Authorization;

use App\Contracts\ProductPermissionResolver;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ProductAuthorization
{
    public function __construct(
        private readonly ProductPermissionResolver $resolver,
        private readonly ResponsibilityProductScopeService $responsibilityScope,
    ) {}

    public function allows(User $user, ProductPermission $permission, ?Product $product = null): bool
    {
        $employee = $user->employee;

        if ($employee?->status !== true) {
            return false;
        }

        if (! $this->resolver->allows($user, $permission, $product)) {
            return false;
        }

        return $product === null || $this->responsibilityScope->canAccessProduct($user, (int) $product->getKey());
    }

    /** Apply the shared Product-module visibility scope; operational selectors use their own context rules. */
    public function applyVisibilityScope(Builder|QueryBuilder $query, User $user): void
    {
        $queryBuilder = $query instanceof Builder ? $query->getQuery() : $query;
        $this->responsibilityScope->apply($queryBuilder, 'products.id', $user);
    }

    public function authorize(User $user, ProductPermission $permission, ?Product $product = null): void
    {
        if (! $this->allows($user, $permission, $product)) {
            throw new AuthorizationException;
        }
    }
}
