<?php

namespace App\Policies;

use App\Enums\InventoryLocationPermission;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\InventoryLocationAuthorization;

class WarehousePolicy
{
    public function __construct(private readonly InventoryLocationAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, InventoryLocationPermission::View);
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->canManage($user);
    }

    public function changeStatus(User $user, Warehouse $warehouse): bool
    {
        return $this->canManage($user);
    }

    public function changeDefault(User $user, Warehouse $warehouse): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return false;
    }

    public function restore(User $user, Warehouse $warehouse): bool
    {
        return false;
    }

    public function forceDelete(User $user, Warehouse $warehouse): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $this->authorization->allows($user, InventoryLocationPermission::Manage);
    }
}
