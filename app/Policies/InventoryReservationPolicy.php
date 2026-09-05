<?php

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;

class InventoryReservationPolicy
{
    public function __construct(private readonly InventoryAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::View);
    }

    public function view(User $user, InventoryReservation $reservation): bool
    {
        return $this->authorization->allows($user, InventoryPermission::View, $reservation->inventory);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::Reserve);
    }

    public function update(User $user, InventoryReservation $reservation): bool
    {
        return false;
    }

    public function delete(User $user, InventoryReservation $reservation): bool
    {
        return false;
    }

    public function restore(User $user, InventoryReservation $reservation): bool
    {
        return false;
    }

    public function forceDelete(User $user, InventoryReservation $reservation): bool
    {
        return false;
    }
}
