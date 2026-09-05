<?php

namespace App\DTOs\Attendance;

use App\Enums\AttendanceStatus;

final readonly class AttendanceDayContext
{
    public function __construct(
        public ?AttendanceStatus $manualOverride,
        public bool $publicHoliday,
        public bool $scheduledOff,
        public bool $approvedCompensatoryOff,
        public ?AttendanceStatus $approvedLeave,
        public bool $hasQualifyingPunch,
        public int $workedMinutes = 0,
        public int $lateMinutes = 0,
        public ?int $halfDayMinimumMinutes = null,
    ) {}
}
