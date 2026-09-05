<?php

namespace App\DTOs\Purchases;

final readonly class PurchaseProductContext
{
    /** @param array<int, array<string, mixed>> $recentReceipts */
    public function __construct(
        public int $productId,
        public int $availableQuantity,
        public int $reservedQuantity,
        public int $damagedQuantity,
        public int $outstandingOnOtherPurchases,
        public ?string $latestReceivedCost = null,
        public ?string $weightedReceivedCost = null,
        public ?string $lowestReceivedCost = null,
        public ?string $highestReceivedCost = null,
        public array $recentReceipts = [],
        public ?string $inventoryAverageCost = null,
        public ?string $inventoryValue = null,
        public ?string $productCostPrice = null,
    ) {}

    public function sellableQuantity(): int
    {
        return $this->availableQuantity - $this->reservedQuantity;
    }

    public function totalOnHand(): int
    {
        return $this->availableQuantity + $this->damagedQuantity;
    }
}
