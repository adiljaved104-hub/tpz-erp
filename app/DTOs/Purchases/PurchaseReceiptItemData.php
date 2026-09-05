<?php

namespace App\DTOs\Purchases;

final readonly class PurchaseReceiptItemData
{
    public function __construct(
        public int $purchaseItemId,
        public int $acceptedQuantity,
        public int $damagedQuantity,
        public int $rejectedQuantity,
        public ?string $notes = null,
    ) {}
}
