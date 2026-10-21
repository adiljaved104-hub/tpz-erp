<?php

namespace App\DTOs\Leave;

final readonly class AnnualLeaveBalance
{
    public function __construct(
        public string $entitlement,
        public string $adjustments,
        public string $used,
        public string $pending,
        public string $remaining,
        public string $availableAfterPending,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public string $historicalUsed = '0.00',
        public string $currentUsed = '0.00',
        public string $unpaidUsed = '0.00',
        public string $carryForward = '0.00',
        public ?int $policyId = null,
    ) {}
}
