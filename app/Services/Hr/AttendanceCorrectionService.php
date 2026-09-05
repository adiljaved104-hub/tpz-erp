<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceCorrection;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttendanceCorrectionService
{
    public function __construct(private readonly HrScopeService $scope, private readonly ActivityLogger $activity) {}

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
}
