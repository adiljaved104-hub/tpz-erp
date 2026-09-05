<?php

namespace App\DTOs\Hikvision;

final readonly class BiometricAttendanceReprocessingResult
{
    /** @param array<string, int> $pendingReasons */
    public function __construct(
        public int $processedEventCount,
        public int $pendingEventCount,
        public int $processedDayCount,
        public int $pendingDayCount,
        public array $pendingReasons = [],
        public int $unchangedDayCount = 0,
        public int $failedDayCount = 0,
    ) {}
}
