<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\ProductPermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;

class RoleBasedProductPermissionResolver implements ProductPermissionResolver
{
    public function __construct(private readonly ?EmployeePermissionOverrideResolver $overrides = null) {}

    public function allows(User $user, ProductPermission $permission, ?Product $product = null): bool
    {
        $roleDefault = $this->roleDefault($user, $permission, $product);

        if ($user->employee?->role === EmployeeRole::Owner) {
            return $roleDefault;
        }

        return $this->overrides?->decision($user, $permission->value) ?? $roleDefault;
    }

    public function roleDefault(User $user, ProductPermission $permission, ?Product $product = null): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => in_array($permission, [
                ProductPermission::View,
                ProductPermission::Create,
                ProductPermission::Update,
                ProductPermission::ViewSellingPrice,
                ProductPermission::EditSellingPrice,
                ProductPermission::Activate,
                ProductPermission::Deactivate,
                ProductPermission::Export,
            ], true),
            EmployeeRole::Manager => in_array($permission, [
                ProductPermission::View,
                ProductPermission::ViewSellingPrice,
                ProductPermission::Export,
            ], true),
            default => false,
        };
    }
}
