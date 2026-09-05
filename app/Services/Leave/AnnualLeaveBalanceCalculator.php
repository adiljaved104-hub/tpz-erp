<?php

namespace App\Services\Leave;

use App\DTOs\Leave\AnnualLeaveBalance;

class AnnualLeaveBalanceCalculator
{
    public function calculate(string $entitlement, string $adjustments, string $approvedUsed, string $pending): AnnualLeaveBalance
    {
        $remaining = bcsub(bcadd($entitlement, $adjustments, 2), $approvedUsed, 2);

        return new AnnualLeaveBalance(
            entitlement: bcadd($entitlement, '0', 2),
            adjustments: bcadd($adjustments, '0', 2),
            used: bcadd($approvedUsed, '0', 2),
            pending: bcadd($pending, '0', 2),
            remaining: $remaining,
            availableAfterPending: bcsub($remaining, $pending, 2),
        );
    }
}
