<?php

namespace Tests\Unit\Hr;

use App\Services\Hr\AttendancePenaltyCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttendancePenaltyCalculatorTest extends TestCase
{
    #[DataProvider('lateOccurrenceCases')]
    public function test_penalty_is_derived_without_rewriting_late_occurrences(int $lates, string $expected): void
    {
        $result = (new AttendancePenaltyCalculator)->calculate($lates, 3, '1.00');

        $this->assertSame($lates, $result['late_occurrences']);
        $this->assertSame($expected, $result['absence_equivalent_penalty_days']);
    }

    public static function lateOccurrenceCases(): array
    {
        return [[0, '0.00'], [2, '0.00'], [3, '1.00'], [6, '2.00']];
    }
}
