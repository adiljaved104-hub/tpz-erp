<?php

namespace App\Actions\Inventory;

use App\DTOs\Inventory\InventoryMutationResult;
use App\DTOs\Inventory\MoveToDamagedData;
use App\Models\User;
use App\Services\Inventory\InventoryService;

class MoveInventoryToDamaged
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(MoveToDamagedData $data, User $actor): InventoryMutationResult
    {
        return $this->inventory->markDamaged($data, $actor);
    }
}
