<?php

namespace App\DTOs\Purchases;

final readonly class ReceivePurchaseData
{
    /** @param array<int, PurchaseReceiptItemData> $items */
    public function __construct(
        public array $items,
        public string $receivedAt,
        public string $idempotencyKey,
        public ?string $supplierDeliveryNote = null,
        public ?string $notes = null,
    ) {}
}
