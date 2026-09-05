<?php

namespace App\DTOs\Inventory;

final readonly class ReserveInventoryData
{
    public function __construct(
        public int $productInventoryId,
        public int $quantity,
        public string $reason,
        public string $idempotencyKey,
    ) {}
}
