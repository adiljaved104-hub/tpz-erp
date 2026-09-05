<?php

namespace Tests\Unit\Purchases;

use App\DTOs\Purchases\PurchaseItemData;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\Purchases\PurchasePriceVarianceService;
use App\Services\Purchases\PurchaseTotalsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PurchaseCalculationsTest extends TestCase
{
    public function test_inventory_unit_cost_excludes_vat_and_allocates_line_discount(): void
    {
        $calculator = new PurchaseTotalsCalculator;
        $line = $calculator->line(new PurchaseItemData(1, 3, '100.0000', '10.00', '5.00'));

        $this->assertSame('96.6667', $line['inventory_unit_cost']);
        $this->assertSame('300.00', $line['line_subtotal']);
        $this->assertSame('290.00', $line['line_net']);
        $this->assertSame('14.50', $line['vat_amount']);
        $this->assertSame('304.50', $line['line_total']);
    }

    public function test_normal_purchase_line_defaults_to_zero_vat(): void
    {
        $calculator = new PurchaseTotalsCalculator;
        $line = $calculator->line(new PurchaseItemData(1, 2, '1600.0000'));
        $document = $calculator->document([$line], '0.00', '0.00', '0.00', '0.00');

        $this->assertSame('0.00', $line['vat_rate']);
        $this->assertSame('0.00', $line['vat_amount']);
        $this->assertSame('3200.00', $line['line_total']);
        $this->assertSame('3200.00', $document['net_before_vat']);
        $this->assertSame('0.00', $document['vat_total']);
        $this->assertSame('3200.00', $document['grand_total']);
    }

    #[DataProvider('varianceCases')]
    public function test_variance_advisories(?string $latest, ?string $entered, ?string $expected): void
    {
        $service = new PurchasePriceVarianceService(new WeightedAverageCostCalculator);

        $this->assertSame($expected, $service->advisory($latest, $entered));
    }

    public static function varianceCases(): array
    {
        return [
            [null, '10.0000', null],
            ['0.0000', '0.0000', null],
            ['0.0000', '1.0000', 'Latest received cost is AED 0.00. Percentage comparison is unavailable.'],
            ['100.0000', '0.0000', 'Entered cost is 100% lower than the latest received cost of AED 100.00.'],
            ['100.0000', '110.0000', 'Entered cost is 10% higher than the latest received cost of AED 100.00.'],
            ['100.0000', '90.0000', 'Entered cost is 10% lower than the latest received cost of AED 100.00.'],
            ['100.0000', '109.9999', null],
        ];
    }
}
