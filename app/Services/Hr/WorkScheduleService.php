<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeaveRequestDay;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkScheduleService
{
    public function __construct(private readonly HrAuthorization $authorization, private readonly ActivityLogger $activity) {}

    /** @param array<string, mixed> $data @param array<int, array<string, mixed>> $days */
    public function create(array $data, array $days, User $actor): WorkSchedule
    {
        $this->authorize($actor);
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', 'unique:work_schedules,code'],
            'timezone' => ['required', 'timezone:all'],
            'schedule_type' => ['required', Rule::in(['standard', 'alternative_weekend', 'custom'])],
            'cycle_length_weeks' => ['required', 'integer', 'min:1', 'max:12'],
        ])->validate();
        $normalizedDays = $this->validateDays($days, (int) $validated['cycle_length_weeks']);

        return DB::transaction(function () use ($validated, $normalizedDays, $actor): WorkSchedule {
            $schedule = WorkSchedule::query()->create($validated + [
                'attendance_policy_id' => DB::table('attendance_policies')->where('status', true)
                    ->whereDate('effective_from', '<=', today())
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
                    ->latest('effective_from')->value('id'),
                'status' => true, 'created_by_user_id' => $actor->id,
            ]);
            foreach ($normalizedDays as $day) {
                WorkScheduleDay::query()->create($day + ['work_schedule_id' => $schedule->id]);
            }
            $this->activity->log('work_schedule.created', $actor, $schedule, ['code' => $schedule->code, 'pattern_days' => count($normalizedDays)]);

            return $schedule->load('days');
        });
    }

    /** @param array<string, mixed> $data @param array<int, array<string, mixed>> $days */
    public function update(WorkSchedule $schedule, array $data, array $days, User $actor): WorkSchedule
    {
        $this->authorize($actor);
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('work_schedules', 'code')->ignore($schedule->id)],
            'timezone' => ['required', 'timezone:all'],
            'schedule_type' => ['required', Rule::in(['standard', 'alternative_weekend', 'custom'])],
            'cycle_length_weeks' => ['required', 'integer', 'min:1', 'max:12'],
        ])->validate();
        $normalizedDays = $this->validateDays($days, (int) $validated['cycle_length_weeks']);

        return DB::transaction(function () use ($schedule, $validated, $normalizedDays, $actor): WorkSchedule {
            $locked = WorkSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $hasAnyPattern = $locked->days()->exists();
            $isOperational = $this->isOperationallyReferenced($locked);
            $changedFields = collect($validated)->filter(fn ($value, string $field): bool => $locked->{$field} !== $value)->keys()->all();
            if ($isOperational && $hasAnyPattern) {
                throw ValidationException::withMessages(['schedule' => 'This Schedule is already in use. Create a new Schedule version and assign it prospectively.']);
            }
            if ($isOperational && $changedFields !== []) {
                throw ValidationException::withMessages(['schedule' => 'Only the missing day pattern can be initialized for this assigned Schedule.']);
            }
            $locked->forceFill($validated)->save();
            $locked->days()->delete();
            foreach ($normalizedDays as $day) {
                WorkScheduleDay::query()->create($day + ['work_schedule_id' => $locked->id]);
            }
            $this->activity->log($isOperational ? 'work_schedule.pattern_initialized' : 'work_schedule.updated', $actor, $locked, ['changed_fields' => $changedFields, 'pattern_days' => count($normalizedDays)]);

            return $locked->load('days');
        });
    }

    /** @param array<int, array<string, mixed>> $days */
    public function configurePattern(WorkSchedule $schedule, array $days, User $actor): WorkSchedule
    {
        $this->authorize($actor);
        $normalizedDays = $this->validateDays($days, (int) $schedule->cycle_length_weeks);

        return DB::transaction(function () use ($schedule, $normalizedDays, $actor): WorkSchedule {
            $locked = WorkSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $isOperational = $this->isOperationallyReferenced($locked);
            if ($isOperational && $locked->days()->exists()) {
                throw ValidationException::withMessages(['schedule' => 'This Schedule is already in use. Create a new Schedule version and assign it prospectively.']);
            }
            $locked->days()->delete();
            foreach ($normalizedDays as $day) {
                WorkScheduleDay::query()->create($day + ['work_schedule_id' => $locked->id]);
            }
            $this->activity->log($isOperational ? 'work_schedule.pattern_initialized' : 'work_schedule.pattern_configured', $actor, $locked, ['pattern_days' => count($normalizedDays)]);

            return $locked->load('days');
        });
    }

    /** @param array<string, mixed> $data */
    public function assign(array $data, User $actor): WorkScheduleAssignment
    {
        $this->authorize($actor);
        $validated = Validator::make($data, [
            'work_schedule_id' => ['required', 'integer', 'exists:work_schedules,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        if ((($validated['employee_id'] ?? null) === null) === (($validated['team_id'] ?? null) === null)) {
            throw ValidationException::withMessages(['employee_id' => 'Select exactly one Employee or Team target.']);
        }

        return DB::transaction(function () use ($validated, $actor): WorkScheduleAssignment {
            $schedule = WorkSchedule::query()->whereKey($validated['work_schedule_id'])->where('status', true)->first();
            if ($schedule === null) {
                throw ValidationException::withMessages(['work_schedule_id' => 'Only an active Work Schedule can be assigned.']);
            }
            $expectedPatternRows = max(1, (int) $schedule->cycle_length_weeks) * 7;
            if ($schedule->days()->count() !== $expectedPatternRows) {
                throw ValidationException::withMessages(['work_schedule_id' => 'Configure and save the complete Work Schedule day pattern before assigning it.']);
            }
            $target = $validated['employee_id'] ?? null
                ? Employee::query()->lockForUpdate()->findOrFail($validated['employee_id'])
                : Team::query()->lockForUpdate()->findOrFail($validated['team_id']);
            if (! $target->status) {
                throw ValidationException::withMessages([$target instanceof Employee ? 'employee_id' : 'team_id' => 'Only an active Employee or Team can receive a Schedule assignment.']);
            }
            $column = isset($validated['employee_id']) ? 'employee_id' : 'team_id';
            $targetId = $target->id;
            $overlap = WorkScheduleAssignment::query()->where($column, $targetId)
                ->whereDate('effective_from', '<=', $validated['effective_to'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $validated['effective_from']))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([$column => 'This assignment overlaps an existing effective Schedule assignment.']);
            }
            $assignment = WorkScheduleAssignment::query()->create($validated + ['assigned_by_user_id' => $actor->id]);
            $this->activity->log('work_schedule.assigned', $actor, $assignment, [
                'work_schedule_id' => $assignment->work_schedule_id, 'target_type' => $column === 'employee_id' ? 'employee' : 'team',
                'target_id' => $targetId, 'effective_from' => $assignment->effective_from->toDateString(),
            ]);

            return $assignment;
        });
    }

    public function setStatus(WorkSchedule $schedule, bool $active, User $actor): WorkSchedule
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($schedule, $active, $actor): WorkSchedule {
            $locked = WorkSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            if (! $active && $locked->assignments()->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))->exists()) {
                throw ValidationException::withMessages(['status' => 'A Schedule with a current or future assignment cannot be deactivated.']);
            }
            $locked->forceFill(['status' => $active])->save();
            $this->activity->log('work_schedule.status_changed', $actor, $locked, ['status' => $active ? 'active' : 'inactive']);

            return $locked;
        });
    }

    public function endAssignment(WorkScheduleAssignment $assignment, string $endDate, string $reason, User $actor): WorkScheduleAssignment
    {
        $this->authorize($actor);
        $validated = Validator::make([
            'end_date' => $endDate,
            'reason' => $reason,
        ], [
            'end_date' => ['required', 'date', 'after_or_equal:'.$assignment->effective_from->toDateString()],
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'end_date.after_or_equal' => 'End Date cannot be before Effective From.',
        ])->validate();

        return DB::transaction(function () use ($assignment, $validated, $actor): WorkScheduleAssignment {
            $locked = WorkScheduleAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $endDate = $validated['end_date'];

            if ($endDate < $locked->effective_from->toDateString()) {
                throw ValidationException::withMessages(['end_date' => 'End Date cannot be before Effective From.']);
            }
            if ($locked->effective_to !== null && $endDate > $locked->effective_to->toDateString()) {
                throw ValidationException::withMessages(['end_date' => 'End Date cannot extend the assignment beyond its current Effective To date.']);
            }

            $oldEffectiveTo = $locked->effective_to?->toDateString();
            $locked->forceFill(['effective_to' => $endDate])->save();
            $this->activity->log('work_schedule.assignment_ended', $actor, $locked, [
                'target_type' => $locked->employee_id !== null ? 'employee' : 'team',
                'target_id' => $locked->employee_id ?? $locked->team_id,
                'old_effective_to' => $oldEffectiveTo,
                'new_effective_to' => $endDate,
                'reason' => $validated['reason'],
            ]);

            return $locked->refresh();
        });
    }

    private function authorize(User $actor): void
    {
        throw_unless($this->authorization->allows($actor, HrPermission::WorkScheduleManage), AuthorizationException::class);
    }

    /** @param array<int, array<string, mixed>> $days @return array<int, array<string, mixed>> */
    private function validateDays(array $days, int $cycleLength): array
    {
        $validated = Validator::make(['days' => $days], [
            'days' => ['required', 'array', 'size:'.($cycleLength * 7)],
            'days.*.cycle_week' => ['required', 'integer', 'between:1,'.$cycleLength],
            'days.*.weekday' => ['required', 'integer', 'between:0,6'],
            'days.*.is_working_day' => ['required', 'boolean'],
            'days.*.expected_start_time' => ['nullable', 'date_format:H:i'],
            'days.*.expected_end_time' => ['nullable', 'date_format:H:i'],
            'days.*.break_start_time' => ['nullable', 'date_format:H:i'],
            'days.*.break_end_time' => ['nullable', 'date_format:H:i'],
            'days.*.earns_compensatory_off' => ['required', 'boolean'],
        ])->validate()['days'];
        $keys = collect($validated)->map(fn (array $day): string => $day['cycle_week'].':'.$day['weekday']);
        if ($keys->unique()->count() !== $cycleLength * 7) {
            throw ValidationException::withMessages(['days' => 'Every weekday in every rotation week must be configured exactly once.']);
        }

        return collect($validated)->map(function (array $day): array {
            $working = (bool) $day['is_working_day'];
            if ($working && (empty($day['expected_start_time']) || empty($day['expected_end_time']))) {
                throw ValidationException::withMessages(['days' => 'Working days require Start and End times.']);
            }
            if ($working && $day['expected_end_time'] <= $day['expected_start_time']) {
                throw ValidationException::withMessages(['days' => 'Working day End time must be after Start time.']);
            }
            if (($day['break_start_time'] === null) !== ($day['break_end_time'] === null)) {
                throw ValidationException::withMessages(['days' => 'Break Start and Break End must either both be set or both be empty.']);
            }

            if (! $working) {
                $day['expected_start_time'] = null;
                $day['expected_end_time'] = null;
                $day['break_start_time'] = null;
                $day['break_end_time'] = null;
                $day['earns_compensatory_off'] = false;
            }

            return $day + ['break_start_time' => null, 'break_end_time' => null];
        })->all();
    }

    private function isOperationallyReferenced(WorkSchedule $schedule): bool
    {
        return $schedule->assignments()->exists()
            || EmployeeAttendance::query()->where('work_schedule_id', $schedule->id)->exists()
            || LeaveRequestDay::query()->where('work_schedule_id', $schedule->id)->exists();
    }
}
