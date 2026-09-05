<?php

namespace App\DTOs\Purchases;

final readonly class PurchaseItemData
{
    public function __construct(
        public int $productId,
        public int $orderedQuantity,
        public string $unitCost,
        public string $lineDiscountTotal = '0.00',
        public string $vatRate = '0.00',
        public ?string $notes = null,
    ) {}
}
