<?php

namespace App\Policies;

use App\Enums\CatalogPermission;
use App\Models\ProductBrand;
use App\Models\User;
use App\Services\Authorization\CatalogAuthorization;

class ProductBrandPolicy
{
    public function __construct(private readonly CatalogAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, CatalogPermission::BrandView);
    }

    public function view(User $user, ProductBrand $brand): bool
    {
        return $this->authorization->allows($user, CatalogPermission::BrandView, $brand);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, CatalogPermission::BrandManage);
    }

    public function update(User $user, ProductBrand $brand): bool
    {
        return $this->authorization->allows($user, CatalogPermission::BrandManage, $brand);
    }

    public function changeStatus(User $user, ProductBrand $brand): bool
    {
        return $this->update($user, $brand);
    }

    public function delete(User $user, ProductBrand $brand): bool
    {
        return false;
    }

    public function restore(User $user, ProductBrand $brand): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductBrand $brand): bool
    {
        return false;
    }
}
