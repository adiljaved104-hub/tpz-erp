<?php

namespace App\Actions\Inventory;

use App\DTOs\Inventory\InventoryMutationResult;
use App\DTOs\Inventory\PostOpeningStockData;
use App\Models\User;
use App\Services\Inventory\InventoryService;

class PostOpeningStock
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(PostOpeningStockData $data, User $actor): InventoryMutationResult
    {
        return $this->inventory->postOpeningStock($data, $actor);
    }
}
