<?php

namespace Tests\Unit\Phase2;

use App\Exceptions\InventoryInvariantException;
use App\Services\Inventory\WeightedAverageCostCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeightedAverageCostCalculatorTest extends TestCase
{
    #[DataProvider('averages')]
    public function test_exact_weighted_average_and_half_up_rounding(int $existingQuantity, ?string $existingAverage, int $inboundQuantity, string $unitCost, string $expected): void
    {
        $this->assertSame($expected, app(WeightedAverageCostCalculator::class)->weightedAverage(
            $existingQuantity,
            $existingAverage,
            $inboundQuantity,
            $unitCost,
        ));
    }

    public static function averages(): array
    {
        return [
            'zero existing quantity' => [0, null, 2, '451', '451.0000'],
            'zero cost is genuine' => [0, null, 1, '0', '0.0000'],
            'simple weighted average' => [1, '1.0000', 1, '2.0000', '1.5000'],
            'half-up boundary' => [1, '0.0000', 1, '0.0001', '0.0001'],
            'below half boundary' => [2, '0.0000', 1, '0.0001', '0.0000'],
            'large exact values' => [2000000000, '99999999999.9999', 1000000000, '99999999999.9998', '99999999999.9999'],
        ];
    }

    public function test_positive_quantity_with_null_average_is_rejected(): void
    {
        $this->expectException(InventoryInvariantException::class);
        app(WeightedAverageCostCalculator::class)->weightedAverage(1, null, 1, '1.0000');
    }

    public function test_fraction_beyond_four_decimals_is_rejected(): void
    {
        $this->expectException(InventoryInvariantException::class);
        app(WeightedAverageCostCalculator::class)->weightedAverage(0, null, 1, '1.00001');
    }
}
