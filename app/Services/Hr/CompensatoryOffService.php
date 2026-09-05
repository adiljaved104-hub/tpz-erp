<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\HrPermission;
use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequestDay;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompensatoryOffService
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly HrScopeService $scope,
        private readonly EffectiveWorkScheduleResolver $schedules,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function requestFromSundayDuty(array $data, User $actor): CompensatoryOff
    {
        throw_unless($this->authorization->allows($actor, HrPermission::WorkScheduleManage), AuthorizationException::class);
        $validated = Validator::make($data, [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'earned_work_date' => ['required', 'date'],
            'off_date' => ['required', 'date', 'after:earned_work_date'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $employee = Employee::query()->findOrFail($validated['employee_id']);
        if (! $employee->status) {
            throw ValidationException::withMessages(['employee_id' => 'Comp Off can only be recorded for an active Employee.']);
        }
        $earned = CarbonImmutable::parse($validated['earned_work_date']);
        if (! $earned->isSunday()) {
            throw ValidationException::withMessages(['earned_work_date' => 'Scheduled Sunday duty must use an actual Sunday date.']);
        }
        $day = $this->schedules->day($employee, $earned)['day'];
        if (! $day->is_working_day || ! $day->earns_compensatory_off) {
            throw ValidationException::withMessages(['earned_work_date' => 'The effective Schedule does not mark this Sunday as eligible duty.']);
        }
        $evidence = EmployeeAttendance::query()->where('employee_id', $employee->id)->whereDate('attendance_date', $earned)
            ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::HalfDay->value])
            ->where(fn ($query) => $query->whereNotNull('first_check_in_at')->orWhere('worked_minutes', '>', 0))->exists();
        if (! $evidence) {
            throw ValidationException::withMessages(['earned_work_date' => 'No qualifying Attendance evidence exists for this Sunday duty.']);
        }
        $reference = $this->references->nextCompensatoryOffReference((int) $earned->format('Y'));

        return DB::transaction(function () use ($validated, $employee, $reference, $actor): CompensatoryOff {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if (CompensatoryOff::query()->where('employee_id', $employee->id)->where('source', 'scheduled_sunday')->whereDate('earned_work_date', $validated['earned_work_date'])->exists()) {
                throw ValidationException::withMessages(['earned_work_date' => 'This Sunday duty already has a Comp Off record.']);
            }
            $this->ensureOffDateIsAvailable($employee, $validated['off_date']);
            $policy = LeavePolicy::query()->where('status', true)->whereDate('effective_from', '<=', $validated['earned_work_date'])
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $validated['earned_work_date']))
                ->latest('effective_from')->firstOrFail();
            $requiresApproval = $policy->compensatory_off_requires_approval;
            $record = CompensatoryOff::query()->create([
                'reference' => $reference, 'employee_id' => $employee->id,
                'earned_work_date' => $validated['earned_work_date'], 'off_date' => $validated['off_date'],
                'source' => 'scheduled_sunday', 'status' => $requiresApproval ? 'pending' : 'approved',
                'granted_by_user_id' => $actor->id,
                'approved_by_user_id' => $requiresApproval ? null : $actor->id,
                'approved_at' => $requiresApproval ? null : now(),
                'reason' => trim($validated['reason']), 'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->activity->log('comp_off.created', $actor, $record, ['reference' => $reference, 'employee_id' => $employee->id, 'status' => $record->status]);

            return $record;
        });
    }

    public function approve(CompensatoryOff $compOff, User $actor): CompensatoryOff
    {
        $compOff->loadMissing('employee');
        throw_unless($this->scope->canApproveEmployee($actor, $compOff->employee), AuthorizationException::class);

        return DB::transaction(function () use ($compOff, $actor): CompensatoryOff {
            $locked = CompensatoryOff::query()->lockForUpdate()->findOrFail($compOff->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only pending Comp Off can be approved.']);
            }
            $this->ensureOffDateIsAvailable($locked->employee, $locked->off_date->toDateString(), $locked->id);
            $locked->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()])->save();
            $this->activity->log('comp_off.approved', $actor, $locked, ['reference' => $locked->reference, 'changed_fields' => ['status']]);

            return $locked;
        });
    }

    private function ensureOffDateIsAvailable(Employee $employee, string $offDate, ?int $exceptCompOffId = null): void
    {
        $compOffConflict = CompensatoryOff::query()->where('employee_id', $employee->id)->whereDate('off_date', $offDate)
            ->when($exceptCompOffId !== null, fn ($query) => $query->whereKeyNot($exceptCompOffId))->exists();
        $leaveConflict = LeaveRequestDay::query()->whereDate('leave_date', $offDate)->where('counts_as_leave', true)
            ->whereHas('request', fn ($query) => $query->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved']))->exists();
        if ($compOffConflict || $leaveConflict) {
            throw ValidationException::withMessages(['off_date' => 'This Employee already has Comp Off or chargeable Leave on the selected date.']);
        }
    }
}
