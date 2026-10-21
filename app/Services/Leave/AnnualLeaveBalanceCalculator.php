<?php

namespace App\Services\Leave;

use App\DTOs\Leave\AnnualLeaveBalance;

class AnnualLeaveBalanceCalculator
{
    public function calculate(string $entitlement, string $adjustments, string $approvedUsed, string $pending, string $carryForward = '0.00'): AnnualLeaveBalance
    {
        $remaining = bcsub(bcadd(bcadd($entitlement, $adjustments, 2), $carryForward, 2), $approvedUsed, 2);

        return new AnnualLeaveBalance(
            entitlement: bcadd($entitlement, '0', 2),
            adjustments: bcadd($adjustments, '0', 2),
            used: bcadd($approvedUsed, '0', 2),
            pending: bcadd($pending, '0', 2),
            remaining: $remaining,
            availableAfterPending: bcsub($remaining, $pending, 2),
            carryForward: bcadd($carryForward, '0', 2),
        );
    }
}
