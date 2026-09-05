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
    ) {}
}
