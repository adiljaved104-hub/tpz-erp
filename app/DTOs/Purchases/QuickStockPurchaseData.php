<?php

namespace App\DTOs\Purchases;

final readonly class QuickStockPurchaseData
{
    /** @param array<int, PurchaseItemData> $items */
    public function __construct(
        public int $warehouseId,
        public string $purchaseDate,
        public array $items,
        public string $idempotencyKey,
        public ?int $supplierId = null,
        public ?int $handledByEmployeeId = null,
        public ?string $supplierInvoiceNumber = null,
        public ?string $supplierInvoiceDate = null,
        public ?string $supplierDeliveryNote = null,
        public ?string $externalAccountingReference = null,
        public string $shippingTotal = '0.00',
        public string $otherChargesTotal = '0.00',
        public ?string $notes = null,
    ) {}
}
