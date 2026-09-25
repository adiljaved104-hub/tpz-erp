<?php

namespace App\DTOs\StockRequests;

final readonly class StockRequestItemData
{
    public function __construct(
        public int $productInventoryId,
        public int $quantity,
    ) {}
}
