<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionService
{
    public function __construct(
        private readonly HrScopeService $scope,
        private readonly ActivityLogger $activity,
        private readonly EffectiveWorkScheduleResolver $schedules,
    ) {}

    /** @param array<string, mixed> $values */
    public function correct(EmployeeAttendance $attendance, array $values, string $reason, User $actor): EmployeeAttendance
    {
        $attendance->loadMissing(['employee', 'schedule']);
        throw_unless($this->scope->canCorrect($actor, $attendance->employee), AuthorizationException::class);

        $validated = Validator::make($values + ['reason' => $reason], [
            'status' => ['required', 'in:'.collect(AttendanceStatus::cases())->pluck('value')->join(',')],
            'first_check_in_at' => ['nullable', 'date'],
            'last_check_out_at' => ['nullable', 'date', 'after_or_equal:first_check_in_at'],
            'late_minutes' => ['required', 'integer', 'min:0'],
            'early_departure_minutes' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $timezone = (string) ($attendance->schedule?->timezone ?: 'Asia/Karachi');
        foreach (['first_check_in_at', 'last_check_out_at'] as $field) {
            if ($validated[$field] !== null) {
                $validated[$field] = CarbonImmutable::parse($validated[$field], $timezone)->utc();
            }
        }

        return DB::transaction(function () use ($attendance, $validated, $actor): EmployeeAttendance {
            $locked = EmployeeAttendance::query()->lockForUpdate()->findOrFail($attendance->id);
            $fields = ['status', 'first_check_in_at', 'last_check_out_at', 'late_minutes', 'early_departure_minutes'];
            $before = $locked->only($fields);
            $after = collect($validated)->only($fields)->all();

            $locked->forceFill($after + ['is_overridden' => true])->save();
            AttendanceCorrection::query()->create([
                'employee_attendance_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'reason' => $validated['reason'],
                'old_values' => $before,
                'new_values' => $after,
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
            $this->activity->log('attendance.corrected', $actor, $locked, ['changed_fields' => array_keys($after)]);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function createManual(array $data, User $actor): EmployeeAttendance
    {
        $data['reason'] = trim((string) ($data['reason'] ?? ''));
        $validated = Validator::make($data, [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', Rule::in([
                AttendanceStatus::Present->value,
                AttendanceStatus::Late->value,
                AttendanceStatus::Absent->value,
                AttendanceStatus::HalfDay->value,
            ])],
            'first_check_in_at' => ['nullable', 'date'],
            'last_check_out_at' => ['nullable', 'date', 'after_or_equal:first_check_in_at'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        $employee = Employee::query()->findOrFail($validated['employee_id']);
        throw_unless($this->scope->canCorrect($actor, $employee), AuthorizationException::class);
        if (! $employee->status) {
            throw ValidationException::withMessages(['employee_id' => 'Manual Attendance can only be recorded for an active Employee.']);
        }
        if (($validated['last_check_out_at'] ?? null) !== null && ($validated['first_check_in_at'] ?? null) === null) {
            throw ValidationException::withMessages(['last_check_out_at' => 'A Check-out requires a matching Check-in.']);
        }

        $date = CarbonImmutable::parse($validated['attendance_date'], 'Asia/Karachi')->startOfDay();
        $scheduleContext = $this->schedules->day($employee, $date);
        $assignment = $scheduleContext['assignment'];
        $day = $scheduleContext['day'];
        $timezone = (string) ($assignment->schedule->timezone ?: 'Asia/Karachi');
        $policy = $assignment->schedule->attendancePolicy;
        $expectedStart = $day->expected_start_time === null ? null : CarbonImmutable::parse($date->format('Y-m-d').' '.$day->expected_start_time, $timezone);
        $expectedEnd = $day->expected_end_time === null ? null : CarbonImmutable::parse($date->format('Y-m-d').' '.$day->expected_end_time, $timezone);
        $first = ($validated['first_check_in_at'] ?? null) === null ? null : CarbonImmutable::parse($validated['first_check_in_at'], $timezone);
        $last = ($validated['last_check_out_at'] ?? null) === null ? null : CarbonImmutable::parse($validated['last_check_out_at'], $timezone);
        foreach ([$first, $last] as $actual) {
            if ($actual !== null && $actual->toDateString() !== $date->toDateString()) {
                throw ValidationException::withMessages(['attendance_date' => 'Check-in and Check-out must fall on the selected Attendance date.']);
            }
        }
        $graceMinutes = $this->schedules->graceMinutes($assignment);
        $rawLateMinutes = $first !== null && $expectedStart !== null && $first->greaterThan($expectedStart)
            ? (int) $expectedStart->diffInMinutes($first)
            : 0;
        $lateThreshold = $assignment->grace_minutes_override !== null || $assignment->schedule?->grace_minutes !== null
            ? $graceMinutes + 1
            : (int) ($policy?->late_after_minutes ?? 1);
        $lateMinutes = $rawLateMinutes >= $lateThreshold ? $rawLateMinutes : 0;
        $status = $validated['status'];
        if ($status === AttendanceStatus::Present->value && $lateMinutes > 0) {
            $status = AttendanceStatus::Late->value;
        }
        $earlyMinutes = $last !== null && $expectedEnd !== null && $last->lessThan($expectedEnd)
            ? (int) $last->diffInMinutes($expectedEnd)
            : 0;
        $workedMinutes = $first !== null && $last !== null ? max(0, (int) $first->diffInMinutes($last)) : null;

        return DB::transaction(function () use ($actor, $employee, $date, $assignment, $policy, $expectedStart, $expectedEnd, $first, $last, $graceMinutes, $lateMinutes, $earlyMinutes, $workedMinutes, $status, $validated): EmployeeAttendance {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            throw_unless($this->scope->canCorrect($actor, $lockedEmployee), AuthorizationException::class);
            if (EmployeeAttendance::query()->where('employee_id', $lockedEmployee->id)->whereDate('attendance_date', $date)->exists()) {
                throw ValidationException::withMessages(['attendance_date' => 'An Attendance record already exists for this Employee and date. Use the correction workflow instead.']);
            }

            $values = [
                'employee_id' => $lockedEmployee->id,
                'attendance_date' => $date->toDateString(),
                'work_schedule_id' => $assignment->work_schedule_id,
                'attendance_policy_id' => $policy?->id,
                'grace_minutes' => $graceMinutes,
                'expected_start_at' => $expectedStart?->utc(),
                'expected_end_at' => $expectedEnd?->utc(),
                'first_check_in_at' => $first?->utc(),
                'last_check_out_at' => $last?->utc(),
                'worked_minutes' => $workedMinutes,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'early_departure_minutes' => $earlyMinutes,
                'source' => 'manual',
                // The normal biometric processor skips overridden rows, so a later sync cannot replace this entry.
                'is_overridden' => true,
                'calculated_at' => now(),
            ];
            $attendance = EmployeeAttendance::query()->create($values);
            $auditValues = collect($values)->except(['employee_id', 'attendance_date'])->all();
            AttendanceCorrection::query()->create([
                'employee_attendance_id' => $attendance->id,
                'actor_user_id' => $actor->id,
                'reason' => trim($validated['reason']),
                'old_values' => [],
                'new_values' => $auditValues,
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
            $this->activity->log('attendance.manually_recorded', $actor, $attendance, [
                'employee_id' => $lockedEmployee->id,
                'attendance_date' => $date->toDateString(),
                'reason' => trim($validated['reason']),
            ]);

            return $attendance->refresh();
        });
    }
}
