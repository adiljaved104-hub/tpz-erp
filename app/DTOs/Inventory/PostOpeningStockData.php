<?php

namespace App\DTOs\Inventory;

final readonly class PostOpeningStockData
{
    public function __construct(
        public int $productId,
        public int $warehouseId,
        public int $availableQuantity,
        public int $damagedQuantity,
        public string $unitCost,
        public string $reason,
        public string $idempotencyKey,
    ) {}
}
