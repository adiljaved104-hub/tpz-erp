<?php

namespace Tests\Unit\Support;

use App\Support\AedMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AedMoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_formats_exact_decimal_strings_for_presentation(string $value, string $expected): void
    {
        $this->assertSame($expected, AedMoney::format($value));
    }

    /** @return array<string, array{string, string}> */
    public static function amounts(): array
    {
        return [
            'thousands' => ['1000.0000', 'AED 1,000.00'],
            'half-up display rounding' => ['1217.1428', 'AED 1,217.14'],
            'whole cost' => ['20.0000', 'AED 20.00'],
            'zero' => ['0.0000', 'AED 0.00'],
        ];
    }
}
