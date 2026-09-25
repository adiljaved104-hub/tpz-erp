<?php

namespace App\DTOs\StockRequests;

use App\Enums\StockRequestPurpose;

final readonly class CreateStockRequestData
{
    /** @param array<int, StockRequestItemData> $items */
    public function __construct(
        public StockRequestPurpose $purpose,
        public ?int $orderId,
        public array $items,
        public string $reason,
        public string $idempotencyKey,
    ) {}
}
