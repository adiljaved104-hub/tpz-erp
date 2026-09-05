<?php

namespace App\DTOs\StockTransfers;

final readonly class CreateStockTransferData
{
    /** @param array<int, StockTransferItemData> $items */
    public function __construct(
        public int $sourceWarehouseId,
        public int $destinationWarehouseId,
        public string $transferDate,
        public array $items,
        public string $idempotencyKey,
        public ?int $handledByEmployeeId = null,
        public ?string $notes = null,
    ) {}
}
