<?php

namespace App\DTOs\Purchases;

use Carbon\CarbonImmutable;

final readonly class PurchaseCostHistorySummary
{
    /** @param array<int, array{date: CarbonImmutable, quantity: int, unit_cost: string, purchase_reference: string, grn_reference: string}> $recentEntries */
    public function __construct(
        public int $productId,
        public string $productName,
        public string $sku,
        public ?string $latestReceivedCost,
        public ?CarbonImmutable $latestReceiptDate,
        public ?int $latestReceivedQuantity,
        public array $recentEntries,
        public ?string $weightedAverageReceivedCost,
    ) {}
}
