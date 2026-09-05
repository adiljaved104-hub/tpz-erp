<?php

namespace App\Policies;

use App\Enums\CatalogPermission;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Authorization\CatalogAuthorization;

class ProductCategoryPolicy
{
    public function __construct(private readonly CatalogAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, CatalogPermission::CategoryView);
    }

    public function view(User $user, ProductCategory $category): bool
    {
        return $this->authorization->allows($user, CatalogPermission::CategoryView, $category);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, CatalogPermission::CategoryManage);
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $this->authorization->allows($user, CatalogPermission::CategoryManage, $category);
    }

    public function changeStatus(User $user, ProductCategory $category): bool
    {
        return $this->update($user, $category);
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return false;
    }

    public function restore(User $user, ProductCategory $category): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductCategory $category): bool
    {
        return false;
    }
}
