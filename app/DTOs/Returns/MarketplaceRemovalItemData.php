<?php

namespace App\DTOs\Returns;

use App\Enums\MarketplaceRemovalSourceStockType;

final readonly class MarketplaceRemovalItemData
{
    public function __construct(
        public int $productId,
        public MarketplaceRemovalSourceStockType $sourceStockType,
        public int $quantity,
        public ?int $customerReturnItemId = null,
    ) {}
}
