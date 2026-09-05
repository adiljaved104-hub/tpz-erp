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
    ) {}
}
