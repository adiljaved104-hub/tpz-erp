<?php

namespace App\DTOs\Purchases;

use Carbon\CarbonImmutable;

final readonly class PurchaseCostHistorySummary
{
    /** @param array<int, array{date: CarbonImmutable, purchase_date: CarbonImmutable, received_at: CarbonImmutable, quantity: int, unit_cost: string, purchase_reference: string, grn_reference: string}> $recentEntries */
    public function __construct(
        public int $productId,
        public string $productName,
        public string $sku,
        public ?CarbonImmutable $latestPurchaseDate,
        public ?string $latestReceivedCost,
        public ?CarbonImmutable $latestReceiptDate,
        public ?int $latestReceivedQuantity,
        public array $recentEntries,
        public ?string $weightedAverageReceivedCost,
    ) {}
}
