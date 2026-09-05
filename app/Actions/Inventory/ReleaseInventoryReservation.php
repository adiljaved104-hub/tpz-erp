<?php

namespace App\Actions\Inventory;

use App\DTOs\Inventory\InventoryMutationResult;
use App\DTOs\Inventory\ReleaseReservationData;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Inventory\InventoryService;

class ReleaseInventoryReservation
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(InventoryReservation $reservation, ReleaseReservationData $data, User $actor): InventoryMutationResult
    {
        return $this->inventory->release($reservation, $data, $actor);
    }
}
