<?php

namespace App\DTOs\Hikvision;

final readonly class BiometricAttendanceBulkRebuildResult
{
    /** @param array<string, int> $pendingReasons */
    public function __construct(
        public int $employeeCount,
        public int $calendarDateCount,
        public int $rebuiltCount,
        public int $unchangedCount,
        public int $skippedCount,
        public int $failedCount,
        public array $pendingReasons = [],
    ) {}
}
