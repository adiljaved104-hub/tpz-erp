<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Models\BiometricAttendanceEvent;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeaveRequestDay;
use App\Services\Attendance\AttendanceInterpretationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BiometricAttendanceProcessor
{
    public function __construct(
        private readonly EffectiveWorkScheduleResolver $schedules,
        private readonly AttendanceDayContextFactory $contexts,
        private readonly AttendanceInterpretationService $interpreter,
    ) {}

    public function rebuild(Employee $employee, CarbonImmutable $day): EmployeeAttendance
    {
        $schedule = $this->schedules->day($employee, $day);
        $timezone = (string) ($schedule['assignment']->schedule->timezone ?: 'Asia/Karachi');
        $attendanceDay = $day->setTimezone($timezone)->startOfDay();
        $start = $attendanceDay;
        $end = $start->endOfDay();
        $events = BiometricAttendanceEvent::query()
            ->whereBetween('punched_at', [$start->utc(), $end->utc()])
            ->whereHas('employeeMappings', fn ($query) => $query
                ->where('employee_id', $employee->id)
                ->whereNull('superseded_at'))
            ->orderBy('punched_at')
            ->get();

        return DB::transaction(function () use ($employee, $attendanceDay, $events, $timezone, $schedule): EmployeeAttendance {
            $policy = $schedule['assignment']->schedule->attendancePolicy;
            $first = $events->first()?->punched_at?->setTimezone($timezone);
            $last = $events->count() > 1 ? $events->last()?->punched_at?->setTimezone($timezone) : null;
            $expectedStart = $schedule['day']->expected_start_time === null ? null : CarbonImmutable::parse(
                $attendanceDay->format('Y-m-d').' '.$schedule['day']->expected_start_time,
                $timezone,
            );
            $expectedEnd = $schedule['day']->expected_end_time === null ? null : CarbonImmutable::parse(
                $attendanceDay->format('Y-m-d').' '.$schedule['day']->expected_end_time,
                $timezone,
            );
            $worked = $first !== null && $last !== null ? max(0, (int) $first->diffInMinutes($last)) : 0;
            $rawLate = $first !== null && $expectedStart !== null && $first->greaterThan($expectedStart)
                ? max(0, (int) $expectedStart->diffInMinutes($first))
                : 0;
            $late = $rawLate >= (int) ($policy?->late_after_minutes ?? 1) ? $rawLate : 0;
            $rawEarly = $last !== null && $expectedEnd !== null && $last->lessThan($expectedEnd)
                ? max(0, (int) $last->diffInMinutes($expectedEnd))
                : 0;
            $approvedLeave = $this->approvedLeave($employee, $attendanceDay);
            $context = $this->contexts->make($employee, $attendanceDay, $events->isNotEmpty(), $worked, $late, $approvedLeave);
            $status = $this->interpreter->interpret($context);
            $nonWorkingStatuses = [
                AttendanceStatus::ApprovedLeave,
                AttendanceStatus::UnpaidLeave,
                AttendanceStatus::PublicHoliday,
                AttendanceStatus::WeekendOff,
                AttendanceStatus::CompensatoryOff,
            ];
            $early = $schedule['day']->is_working_day && ! in_array($status, $nonWorkingStatuses, true)
                ? $rawEarly
                : 0;

            $attendance = EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $attendanceDay->format('Y-m-d'))
                ->lockForUpdate()
                ->first();
            $attendance ??= new EmployeeAttendance([
                'employee_id' => $employee->id,
                'attendance_date' => $attendanceDay->format('Y-m-d'),
            ]);
            if (! $attendance->exists || ! $attendance->is_overridden) {
                $attendance->fill([
                    'work_schedule_id' => $schedule['assignment']->schedule->id,
                    'attendance_policy_id' => $policy?->id,
                    // Calculations use the effective Schedule timezone above;
                    // datetime columns follow the application's UTC convention.
                    'expected_start_at' => $expectedStart?->utc(),
                    'expected_end_at' => $expectedEnd?->utc(),
                    'first_check_in_at' => $first?->utc(),
                    'last_check_out_at' => $last?->utc(),
                    'worked_minutes' => $worked,
                    'status' => $status,
                    'late_minutes' => $late,
                    'early_departure_minutes' => $early,
                    'source' => 'biometric',
                    'is_overridden' => false,
                    'calculated_at' => now(),
                ])->save();
            }
            foreach ($events as $event) {
                DB::table('attendance_evidence_links')->insertOrIgnore([
                    'employee_attendance_id' => $attendance->id,
                    'biometric_attendance_event_id' => $event->id,
                    'created_at' => now(),
                ]);
            }

            return $attendance->refresh();
        });
    }

    private function approvedLeave(Employee $employee, CarbonImmutable $day): ?AttendanceStatus
    {
        $leave = LeaveRequestDay::query()
            ->whereDate('leave_date', $day)
            ->where('counts_as_leave', true)
            ->whereHas('request', fn ($query) => $query->where('employee_id', $employee->id)->where('status', 'approved'))
            ->with('request.type')
            ->first();

        return match ($leave?->request?->type?->code) {
            'annual' => AttendanceStatus::ApprovedLeave,
            'unpaid' => AttendanceStatus::UnpaidLeave,
            default => null,
        };
    }
}
