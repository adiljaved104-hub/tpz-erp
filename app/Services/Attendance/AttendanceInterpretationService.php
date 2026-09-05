<?php

namespace App\Services\Attendance;

use App\DTOs\Attendance\AttendanceDayContext;
use App\Enums\AttendanceStatus;

class AttendanceInterpretationService
{
    public function interpret(AttendanceDayContext $context): AttendanceStatus
    {
        if ($context->manualOverride !== null) {
            return $context->manualOverride;
        }
        if ($context->publicHoliday) {
            return AttendanceStatus::PublicHoliday;
        }
        if ($context->scheduledOff) {
            return AttendanceStatus::WeekendOff;
        }
        if ($context->approvedCompensatoryOff) {
            return AttendanceStatus::CompensatoryOff;
        }
        if ($context->approvedLeave !== null) {
            return $context->approvedLeave;
        }
        if (! $context->hasQualifyingPunch) {
            return AttendanceStatus::Absent;
        }
        if ($context->halfDayMinimumMinutes !== null && $context->workedMinutes < $context->halfDayMinimumMinutes) {
            return AttendanceStatus::HalfDay;
        }

        return $context->lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::Present;
    }
}
