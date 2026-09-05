<?php

namespace Tests\Unit\Hr;

use App\Services\Leave\AnnualLeaveBalanceCalculator;
use PHPUnit\Framework\TestCase;

class AnnualLeaveBalanceCalculatorTest extends TestCase
{
    public function test_remaining_uses_entitlement_adjustments_and_approved_leave_only(): void
    {
        $balance = (new AnnualLeaveBalanceCalculator)->calculate('12', '1.50', '4', '2');

        self::assertSame('12.00', $balance->entitlement);
        self::assertSame('4.00', $balance->used);
        self::assertSame('2.00', $balance->pending);
        self::assertSame('9.50', $balance->remaining);
        self::assertSame('7.50', $balance->availableAfterPending);
    }
}
