<?php

namespace App\DTOs\Inventory;

use App\Models\ProductInventory;
use Illuminate\Support\Collection;

final readonly class InventoryMutationResult
{
    /** @param Collection<int, mixed> $movements */
    public function __construct(
        public ProductInventory $inventory,
        public Collection $movements,
        public mixed $source = null,
    ) {}
}
