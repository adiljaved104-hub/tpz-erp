<?php

namespace App\DTOs\Purchases;

final readonly class CreatePurchaseData
{
    /** @param array<int, PurchaseItemData> $items */
    public function __construct(
        public ?int $supplierId,
        public int $warehouseId,
        public string $purchaseDate,
        public array $items,
        public ?string $supplierInvoiceNumber = null,
        public ?string $supplierInvoiceDate = null,
        public ?string $expectedDeliveryDate = null,
        public ?string $externalAccountingReference = null,
        public string $shippingTotal = '0.00',
        public string $shippingVatRate = '0.00',
        public string $otherChargesTotal = '0.00',
        public string $otherChargesVatRate = '0.00',
        public ?string $notes = null,
    ) {}
}
