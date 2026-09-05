<?php

namespace App\Services\ServiceCases;

use App\Models\WarrantyRepair;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class WarrantySlaService
{
    public const DAYS = 14;

    public const DUE_SOON_DAYS = 3;

    public function dueAt(WarrantyRepair $case): CarbonImmutable
    {
        return $this->dueFrom($case->received_at);
    }

    public function dueFrom(DateTimeInterface|string $receivedAt): CarbonImmutable
    {
        return CarbonImmutable::parse($receivedAt)->addDays(self::DAYS);
    }

    public function daysLeftLabel(WarrantyRepair $case, ?CarbonImmutable $now = null): string
    {
        $days = $this->daysLeft($case, $now);

        if ($days < 0) {
            $overdue = abs($days);

            return $overdue.' '.str('day')->plural($overdue).' overdue';
        }

        return $days.' '.str('day')->plural($days);
    }

    public function daysLeft(WarrantyRepair $case, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $effective = $case->completed_at ?? $now;

        return (int) $effective->startOfDay()->diffInDays($this->dueAt($case)->startOfDay(), false);
    }

    public function status(WarrantyRepair $case, ?CarbonImmutable $now = null): string
    {
        $daysLeft = $this->daysLeft($case, $now);

        if ($case->completed_at !== null) {
            return $case->completed_at->lessThanOrEqualTo($this->dueAt($case))
                ? 'Completed Within SLA'
                : 'SLA Breached';
        }

        if ($daysLeft < 0) {
            return 'Overdue';
        }

        return $daysLeft <= self::DUE_SOON_DAYS ? 'Due Soon' : 'On Track';
    }
}
