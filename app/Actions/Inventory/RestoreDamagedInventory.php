<?php

namespace App\Actions\Inventory;

use App\DTOs\Inventory\InventoryMutationResult;
use App\DTOs\Inventory\RestoreDamagedData;
use App\Models\User;
use App\Services\Inventory\InventoryService;

class RestoreDamagedInventory
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(RestoreDamagedData $data, User $actor): InventoryMutationResult
    {
        return $this->inventory->restoreDamaged($data, $actor);
    }
}
