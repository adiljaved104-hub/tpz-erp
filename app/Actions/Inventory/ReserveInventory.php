<?php

namespace App\Actions\Inventory;

use App\DTOs\Inventory\InventoryMutationResult;
use App\DTOs\Inventory\ReserveInventoryData;
use App\Models\User;
use App\Services\Inventory\InventoryService;

class ReserveInventory
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(ReserveInventoryData $data, User $actor): InventoryMutationResult
    {
        return $this->inventory->reserve($data, $actor);
    }
}
