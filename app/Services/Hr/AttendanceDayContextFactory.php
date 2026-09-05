<?php

namespace App\Services\Hr;

use App\DTOs\Attendance\AttendanceDayContext;
use App\Enums\AttendanceStatus;
use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;

class AttendanceDayContextFactory
{
    public function __construct(private readonly EffectiveWorkScheduleResolver $schedules) {}

    public function make(
        Employee $employee,
        CarbonImmutable $date,
        bool $hasQualifyingPunch,
        int $workedMinutes = 0,
        int $lateMinutes = 0,
        ?AttendanceStatus $approvedLeave = null,
        ?AttendanceStatus $manualOverride = null,
    ): AttendanceDayContext {
        $schedule = $this->schedules->day($employee, $date);
        $holiday = PublicHoliday::query()->where('status', true)->whereDate('holiday_date', $date)->exists();
        $compOff = CompensatoryOff::query()->where('employee_id', $employee->id)->whereIn('status', ['approved', 'used'])->whereDate('off_date', $date)->exists();

        return new AttendanceDayContext(
            publicHoliday: $holiday,
            scheduledOff: ! $schedule['day']->is_working_day,
            approvedCompensatoryOff: $compOff,
            approvedLeave: $approvedLeave,
            hasQualifyingPunch: $hasQualifyingPunch,
            workedMinutes: $workedMinutes,
            halfDayMinimumMinutes: $schedule['assignment']->schedule->attendancePolicy?->half_day_minimum_minutes,
            lateMinutes: $lateMinutes,
            manualOverride: $manualOverride,
        );
    }
}
