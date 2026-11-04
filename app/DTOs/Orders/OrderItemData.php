<?php

namespace App\DTOs\Orders;

final readonly class OrderItemData
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public string $sellingPrice,
        public string $discountTotal = '0.00',
        public string $vatRate = '0.0000',
        public ?string $notes = null,
        public ?int $salesConfigurationId = null,
        public ?int $upgradeRecipeId = null,
        /** @var array<int, int>|null */
        public ?array $allocationSources = null,
        public string $sourceType = 'existing_product',
        public ?string $manualDescription = null,
        public ?string $manualBrand = null,
        public ?string $manualCategory = null,
        public ?string $manualModel = null,
        public ?string $manualCondition = null,
        public ?int $supplierId = null,
        public string $sourceMode = 'warehouse_receipt',
        public ?string $actualUnitCost = null,
        public ?string $purchaseCurrency = null,
        public ?string $purchasedOn = null,
    ) {}
}
