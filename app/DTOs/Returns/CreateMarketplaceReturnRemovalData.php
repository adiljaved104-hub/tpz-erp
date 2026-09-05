<?php

namespace App\DTOs\Returns;

final readonly class CreateMarketplaceReturnRemovalData
{
    /** @param array<int, MarketplaceRemovalItemData> $items */
    public function __construct(
        public int $platformId,
        public int $sourceWarehouseId,
        public int $destinationWarehouseId,
        public array $items,
        public string $idempotencyKey,
        public ?string $externalReference = null,
        public ?string $notes = null,
    ) {}
}
