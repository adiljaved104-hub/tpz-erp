<?php

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;

class StockMovementPolicy
{
    public function __construct(private readonly InventoryAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::ViewMovements);
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $this->authorization->allows($user, InventoryPermission::ViewMovements, $movement->inventory);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function restore(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function forceDelete(User $user, StockMovement $movement): bool
    {
        return false;
    }
}
