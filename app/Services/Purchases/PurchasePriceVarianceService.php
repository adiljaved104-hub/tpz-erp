<?php

namespace App\Services\Purchases;

use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Support\AedMoney;

class PurchasePriceVarianceService
{
    public function __construct(private readonly WeightedAverageCostCalculator $decimals) {}

    public function advisory(?string $latest, ?string $entered): ?string
    {
        if ($latest === null || $entered === null || trim($entered) === '') {
            return null;
        }

        $latest = $this->decimals->normalize($latest);
        $entered = $this->decimals->normalize($entered);

        if (bccomp($latest, '0.0000', 4) === 0) {
            return bccomp($entered, '0.0000', 4) === 0
                ? null
                : 'Latest received cost is '.AedMoney::format($latest).'. Percentage comparison is unavailable.';
        }

        $difference = bcsub($entered, $latest, 4);
        $percentage = bcdiv(bcmul($difference, '100', 8), $latest, 4);

        if (bccomp(ltrim($percentage, '-'), '10.0000', 4) < 0) {
            return null;
        }

        $direction = str_starts_with($percentage, '-') ? 'lower' : 'higher';
        $display = rtrim(rtrim(ltrim($percentage, '-'), '0'), '.');

        return "Entered cost is {$display}% {$direction} than the latest received cost of ".AedMoney::format($latest).'.';
    }
}
