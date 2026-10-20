<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveRequestEvent;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use App\Services\Notifications\LeaveNotificationDispatcher;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly HrScopeService $scope,
        private readonly LeaveDayCalculator $days,
        private readonly LeaveBalanceService $balances,
        private readonly ReferenceSequenceService $references,
        private readonly LeaveNotificationDispatcher $notifications,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function submit(array $data, User $actor): LeaveRequest
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveRequest), AuthorizationException::class);
        $employee = $actor->employee;
        throw_unless($employee instanceof Employee, AuthorizationException::class);
        $validated = $this->validatedRequestData($data);
        $type = $this->availableLeaveType((int) $validated['leave_type_id']);
        $request = $this->createRequest($validated, $employee, $type, $actor);
        $this->notifications->submitted($request);

        return $request;
    }

    /** @param array<string, mixed> $data
     * @return array{working_days: string, available_after_pending: ?string, annual_leave: bool}
     */
    public function previewOnBehalf(array $data, User $actor): array
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveRecordOnBehalf), AuthorizationException::class);
        $validated = Validator::make($data, [
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ])->validate();
        $employee = $this->scope->recordableEmployeeQuery($actor)->whereKey($validated['employee_id'])->first();
        throw_unless($employee instanceof Employee, AuthorizationException::class);
        $type = $this->availableLeaveType((int) $validated['leave_type_id']);
        $from = CarbonImmutable::parse($validated['from_date']);
        $calculation = $this->days->calculate($employee, $from, CarbonImmutable::parse($validated['to_date']));
        $balance = $type->consumes_annual_entitlement
            ? $this->balances->for($employee, (int) $from->format('Y'))
            : null;

        return [
            'working_days' => $calculation['working_days'],
            'available_after_pending' => $balance?->availableAfterPending,
            'annual_leave' => $type->consumes_annual_entitlement,
        ];
    }

    /** @param array<string, mixed> $data */
    public function recordOnBehalf(array $data, User $actor): LeaveRequest
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveRecordOnBehalf), AuthorizationException::class);
        $validated = Validator::make($data, [
            'employee_id' => ['required', 'integer'],
            'approval_status' => ['required', 'in:pending,approved'],
        ])->validate();
        $employee = $this->scope->recordableEmployeeQuery($actor)->whereKey($validated['employee_id'])->first();
        throw_unless($employee instanceof Employee, AuthorizationException::class);
        $requestData = $this->validatedRequestData($data);
        $type = $this->availableLeaveType((int) $requestData['leave_type_id']);
        $approved = $validated['approval_status'] === 'approved';

        if ($approved) {
            throw_unless($this->scope->canApproveEmployee($actor, $employee), AuthorizationException::class);
        }

        $request = $this->createRequest($requestData, $employee, $type, $actor, true, $approved);
        if ($approved) {
            $this->notifications->decided($request);
        } else {
            $this->notifications->submitted($request);
        }

        return $request;
    }

    public function approve(LeaveRequest $request, User $actor): LeaveRequest
    {
        $request->loadMissing(['employee', 'type']);
        throw_unless($this->scope->canApprove($actor, $request), AuthorizationException::class);

        $updated = DB::transaction(function () use ($request, $actor): LeaveRequest {
            $locked = LeaveRequest::query()->with(['employee', 'type'])->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only a pending Leave request can be approved.']);
            }
            Employee::query()->lockForUpdate()->findOrFail($locked->employee_id);
            if ($locked->type->consumes_annual_entitlement) {
                $balance = $this->balances->for($locked->employee, (int) $locked->from_date->format('Y'));
                if (bccomp((string) $locked->requested_working_days, $balance->remaining, 2) > 0) {
                    throw ValidationException::withMessages(['status' => 'The employee no longer has enough Annual Leave available.']);
                }
            }
            $locked->forceFill(['status' => 'approved', 'decided_by_user_id' => $actor->id, 'decided_at' => now()])->save();
            $this->event($locked, 'approved', 'pending', 'approved', $actor);
            $this->activity->log('leave.approved', $actor, $locked, ['reference' => $locked->reference, 'changed_fields' => ['status']]);

            return $locked->refresh();
        });
        $this->notifications->decided($updated);

        return $updated;
    }

    public function reject(LeaveRequest $request, string $reason, User $actor): LeaveRequest
    {
        $request->loadMissing('employee');
        throw_unless($this->scope->canApprove($actor, $request), AuthorizationException::class);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();

        $updated = DB::transaction(function () use ($request, $reason, $actor): LeaveRequest {
            $locked = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only a pending Leave request can be rejected.']);
            }
            $locked->forceFill(['status' => 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => now(), 'decision_reason' => trim($reason)])->save();
            $this->event($locked, 'rejected', 'pending', 'rejected', $actor, $reason);
            $this->activity->log('leave.rejected', $actor, $locked, ['reference' => $locked->reference, 'changed_fields' => ['status']]);

            return $locked->refresh();
        });
        $this->notifications->decided($updated);

        return $updated;
    }

    public function cancel(LeaveRequest $request, User $actor): LeaveRequest
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveRequest)
            && $actor->employee?->id === $request->employee_id, AuthorizationException::class);

        return DB::transaction(function () use ($request, $actor): LeaveRequest {
            $locked = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only your pending Leave request can be cancelled.']);
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            $this->event($locked, 'cancelled', 'pending', 'cancelled', $actor);
            $this->activity->log('leave.cancelled', $actor, $locked, ['reference' => $locked->reference, 'changed_fields' => ['status']]);

            return $locked->refresh();
        });
    }

    private function policyFor(CarbonImmutable $date): LeavePolicy
    {
        return LeavePolicy::query()->where('status', true)->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->latest('effective_from')->firstOrFail();
    }

    /** @param array<string, mixed> $data
     * @return array{leave_type_id: int, from_date: string, to_date: string, reason: string}
     */
    private function validatedRequestData(array $data): array
    {
        return Validator::make($data, [
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
    }

    private function availableLeaveType(int $id): LeaveType
    {
        $type = LeaveType::query()->whereKey($id)->where('status', true)->firstOrFail();
        if (! in_array($type->code, ['annual', 'unpaid'], true)) {
            throw ValidationException::withMessages(['leave_type_id' => 'This Leave Type is not available in the B1 request workflow.']);
        }

        return $type;
    }

    /** @param array<string, mixed> $validated
     */
    private function createRequest(array $validated, Employee $employee, LeaveType $type, User $actor, bool $managementRecorded = false, bool $approved = false): LeaveRequest
    {
        $from = CarbonImmutable::parse($validated['from_date']);
        $to = CarbonImmutable::parse($validated['to_date']);
        $policy = $this->policyFor($from);
        $calculation = $this->days->calculate($employee, $from, $to);
        $reference = $this->references->nextLeaveRequestReference((int) $from->format('Y'));

        return DB::transaction(function () use ($validated, $employee, $type, $policy, $calculation, $reference, $actor, $managementRecorded, $approved, $from, $to): LeaveRequest {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($lockedEmployee->status !== true) {
                throw ValidationException::withMessages(['employee_id' => 'Leave can only be recorded for an active employee.']);
            }

            if ($managementRecorded) {
                $this->assertNoManagementRecordConflicts($lockedEmployee, $from, $to, $calculation['days']);
            }

            if ($type->consumes_annual_entitlement) {
                $balance = $this->balances->for($lockedEmployee, (int) $from->format('Y'));
                $available = $approved ? $balance->remaining : $balance->availableAfterPending;
                if (bccomp($calculation['working_days'], $available, 2) > 0) {
                    $message = $approved
                        ? 'The employee no longer has enough Annual Leave available to approve this record.'
                        : 'The request exceeds the Annual Leave available after pending requests.';
                    throw ValidationException::withMessages(['from_date' => $message]);
                }
            }

            $request = LeaveRequest::query()->create([
                'reference' => $reference,
                'employee_id' => $lockedEmployee->id,
                'team_id_at_request' => $lockedEmployee->team_id,
                'leave_type_id' => $type->id,
                'leave_policy_id' => $policy->id,
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'requested_working_days' => $calculation['working_days'],
                'annual_entitlement_snapshot' => $type->consumes_annual_entitlement ? $policy->annual_leave_entitlement_days : null,
                'status' => $approved ? 'approved' : 'pending',
                'reason' => trim($validated['reason']),
                'submitted_at' => now(),
                'decided_by_user_id' => $approved ? $actor->id : null,
                'decided_at' => $approved ? now() : null,
                'recorded_source' => $managementRecorded ? 'management' : 'employee',
                'recorded_by_user_id' => $managementRecorded ? $actor->id : null,
                'recorded_at' => $managementRecorded ? now() : null,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            foreach ($calculation['days'] as $day) {
                LeaveRequestDay::query()->create($day + ['leave_request_id' => $request->id]);
            }

            $this->event($request, 'submitted', null, 'pending', $actor);
            if ($approved) {
                $this->event($request, 'approved', 'pending', 'approved', $actor);
            }
            $this->activity->log(
                $managementRecorded ? 'leave.recorded_on_behalf' : 'leave.submitted',
                $actor,
                $request,
                ['reference' => $reference, 'working_days' => $calculation['working_days'], 'recorded_source' => $request->recorded_source],
            );
            if ($approved) {
                $this->activity->log('leave.approved', $actor, $request, ['reference' => $reference, 'changed_fields' => ['status']]);
            }

            return $request;
        });
    }

    /** @param array<int, array<string, mixed>> $days */
    private function assertNoManagementRecordConflicts(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, array $days): void
    {
        $overlapping = LeaveRequest::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages(['from_date' => 'The selected dates overlap an existing pending or approved leave request.']);
        }

        $workingDates = collect($days)->where('counts_as_leave', true)->pluck('leave_date')->all();
        $attendanceQuery = EmployeeAttendance::query()->where('employee_id', $employee->id);
        if ($workingDates !== []) {
            $attendanceQuery->where(function ($query) use ($workingDates): void {
                foreach ($workingDates as $date) {
                    $query->orWhereDate('attendance_date', $date);
                }
            });
        }
        if ($workingDates !== [] && $attendanceQuery->exists()) {
            throw ValidationException::withMessages(['from_date' => 'Attendance already exists on one or more selected working days. Existing attendance will not be changed.']);
        }

        if (CompensatoryOff::query()->where('employee_id', $employee->id)->where('status', 'pending')
            ->whereDate('off_date', '>=', $from->toDateString())->whereDate('off_date', '<=', $to->toDateString())->exists()) {
            throw ValidationException::withMessages(['from_date' => 'A pending Comp Off request overlaps the selected dates. Resolve it before recording leave.']);
        }
    }

    private function event(LeaveRequest $request, string $type, ?string $from, string $to, User $actor, ?string $reason = null): void
    {
        LeaveRequestEvent::query()->create([
            'leave_request_id' => $request->id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $actor->id, 'reason' => $reason, 'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now(), 'created_at' => now(),
        ]);
    }
}
