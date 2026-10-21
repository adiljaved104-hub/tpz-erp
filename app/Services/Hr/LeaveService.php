<?php

namespace App\Services\Hr;

use App\DTOs\Leave\AnnualLeaveBalance;
use App\Enums\AttendanceStatus;
use App\Enums\HrPermission;
use App\Models\AttendanceCorrection;
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
        private readonly HistoricalLeaveActivationService $historicalActivation,
    ) {}

    /** @param array<string, mixed> $data */
    public function submit(array $data, User $actor): LeaveRequest
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveRequest), AuthorizationException::class);
        $employee = $actor->employee;
        throw_unless($employee instanceof Employee, AuthorizationException::class);
        $validated = $this->validatedRequestData($data);
        $type = $this->availableLeaveType((int) $validated['leave_type_id']);
        if ($type->consumes_annual_entitlement) {
            $this->historicalActivation->assertAnnualRequestsActive($employee);
        }
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
        if ($type->consumes_annual_entitlement) {
            $this->historicalActivation->assertAnnualRequestsActive($employee);
        }
        $from = CarbonImmutable::parse($validated['from_date']);
        $calculation = $this->days->calculate($employee, $from, CarbonImmutable::parse($validated['to_date']));
        $available = $type->consumes_annual_entitlement
            ? $this->availableAfterPendingForRange($employee, $from, CarbonImmutable::parse($validated['to_date']), $calculation['days'])
            : null;

        return [
            'working_days' => $calculation['working_days'],
            'available_after_pending' => $available,
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
        if ($type->consumes_annual_entitlement) {
            $this->historicalActivation->assertAnnualRequestsActive($employee);
        }
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
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($locked->employee_id);
            $locked->setRelation('employee', $lockedEmployee);
            $this->assertNoOverlappingLeaveRequest($locked);
            if ($locked->type->consumes_annual_entitlement) {
                $storedDays = $locked->days()->get()->map(fn (LeaveRequestDay $day): array => [
                    'leave_date' => $day->leave_date->toDateString(),
                    'counts_as_leave' => (bool) $day->counts_as_leave,
                    'leave_units' => (string) $day->leave_units,
                ])->all();
                $days = $storedDays !== []
                    ? $storedDays
                    : $this->days->calculate($locked->employee, CarbonImmutable::parse($locked->from_date), CarbonImmutable::parse($locked->to_date))['days'];

                if ($this->historicalActivation->isActive()) {
                    $this->assertAnnualAvailability($locked->employee, CarbonImmutable::parse($locked->from_date), CarbonImmutable::parse($locked->to_date), $days, 'remaining');
                } else {
                    // An already-pending ERP request remains approvable under the
                    // existing calendar-year policy while HR reconciles history.
                    $this->assertLegacyAnnualAvailability($locked->employee, $days);
                }
            }
            $this->reconcileAbsentAttendanceForApprovedLeave($locked, $actor);
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
        $calculation = $this->days->calculate($employee, $from, $to);
        $policy = $type->consumes_annual_entitlement ? null : $this->policyFor($from);
        $reference = $this->references->nextLeaveRequestReference((int) $from->format('Y'));

        return DB::transaction(function () use ($validated, $employee, $type, $policy, $calculation, $reference, $actor, $managementRecorded, $approved, $from, $to): LeaveRequest {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($lockedEmployee->status !== true) {
                throw ValidationException::withMessages(['employee_id' => 'Leave can only be recorded for an active employee.']);
            }

            $effectivePolicy = $type->consumes_annual_entitlement
                ? $this->balances->policyForDate($lockedEmployee, $from)
                : $policy;

            if ($managementRecorded) {
                $this->assertNoManagementRecordConflicts($lockedEmployee, $from, $to, $calculation['days']);
            }

            if ($type->consumes_annual_entitlement) {
                $this->assertAnnualAvailability(
                    $lockedEmployee,
                    $from,
                    $to,
                    $calculation['days'],
                    $approved ? 'remaining' : 'availableAfterPending',
                );
            }

            $request = LeaveRequest::query()->create([
                'reference' => $reference,
                'employee_id' => $lockedEmployee->id,
                'team_id_at_request' => $lockedEmployee->team_id,
                'leave_type_id' => $type->id,
                'leave_policy_id' => $effectivePolicy->id,
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'requested_working_days' => $calculation['working_days'],
                'annual_entitlement_snapshot' => $type->consumes_annual_entitlement ? $effectivePolicy->annual_leave_entitlement_days : null,
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

            if ($approved) {
                $this->reconcileAbsentAttendanceForApprovedLeave($request, $actor);
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

    /**
     * @param  array<int, array<string, mixed>>  $days
     */
    private function assertAnnualAvailability(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, array $days, string $availabilityField): void
    {
        foreach ($this->annualBalanceSlices($employee, $from, $to, $days) as $slice) {
            if (bccomp($slice['requested'], $slice['balance']->{$availabilityField}, 2) <= 0) {
                continue;
            }

            $message = $availabilityField === 'remaining'
                ? 'The employee no longer has enough Annual Leave available in one or more joining-anniversary leave periods.'
                : 'The request exceeds the Annual Leave available after pending requests in one or more joining-anniversary leave periods.';
            throw ValidationException::withMessages(['from_date' => $message]);
        }
    }

    /** @param array<int, array<string, mixed>> $days */
    private function assertLegacyAnnualAvailability(Employee $employee, array $days): void
    {
        $requestedByYear = [];
        foreach ($days as $day) {
            if (! (bool) ($day['counts_as_leave'] ?? false)) {
                continue;
            }
            $year = (int) CarbonImmutable::parse($day['leave_date'])->format('Y');
            $requestedByYear[$year] = bcadd($requestedByYear[$year] ?? '0.00', (string) $day['leave_units'], 2);
        }
        foreach ($requestedByYear as $year => $requested) {
            if (bccomp($requested, $this->balances->for($employee, $year)->remaining, 2) > 0) {
                throw ValidationException::withMessages(['from_date' => 'The employee no longer has enough Annual Leave in the existing calendar-year policy.']);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return array<string, array{balance: AnnualLeaveBalance, requested: string}>
     */
    private function annualBalanceSlices(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, array $days): array
    {
        $balancesByPeriod = [];
        $sliceForDate = function (CarbonImmutable $date) use ($employee, &$balancesByPeriod): array {
            $balance = $this->balances->forDate($employee, $date);
            $period = $balance->periodStart;
            if ($period === null) {
                throw ValidationException::withMessages(['joining_date' => 'The employee’s joining-anniversary leave period could not be resolved.']);
            }

            $balancesByPeriod[$period] ??= $balance;

            return [$period, $balancesByPeriod[$period]];
        };

        // Validate the entire requested interval, even when an endpoint is a weekend or holiday.
        $sliceForDate($from);
        $sliceForDate($to);
        $slices = [];

        foreach ($days as $day) {
            if (! (bool) ($day['counts_as_leave'] ?? false)) {
                continue;
            }

            $date = CarbonImmutable::parse($day['leave_date'])->startOfDay();
            [$period, $balance] = $sliceForDate($date);
            $slices[$period] ??= ['balance' => $balance, 'requested' => '0.00'];
            $slices[$period]['requested'] = bcadd($slices[$period]['requested'], (string) $day['leave_units'], 2);
        }

        if ($slices === []) {
            [$period, $balance] = $sliceForDate($from);
            $slices[$period] = ['balance' => $balance, 'requested' => '0.00'];
        }

        return $slices;
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     */
    private function availableAfterPendingForRange(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, array $days): string
    {
        $available = '0.00';
        foreach ($this->annualBalanceSlices($employee, $from, $to, $days) as $slice) {
            $available = bcadd($available, $slice['balance']->availableAfterPending, 2);
        }

        return $available;
    }

    /** @param array<int, array<string, mixed>> $days */
    private function assertNoManagementRecordConflicts(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, array $days): void
    {
        $this->assertNoOverlappingLeaveRequestForEmployee($employee->id, $from, $to);

        $workingDates = collect($days)->where('counts_as_leave', true)->pluck('leave_date')->all();
        $attendanceQuery = EmployeeAttendance::query()->where('employee_id', $employee->id)
            ->where('status', '!=', AttendanceStatus::Absent->value);
        if ($workingDates !== []) {
            $attendanceQuery->where(function ($query) use ($workingDates): void {
                foreach ($workingDates as $date) {
                    $query->orWhereDate('attendance_date', $date);
                }
            });
        }
        if ($workingDates !== [] && $attendanceQuery->exists()) {
            throw ValidationException::withMessages(['from_date' => 'Attendance already exists as a non-Absent status on one or more selected working days. Existing attendance will not be changed.']);
        }

        if (CompensatoryOff::query()->where('employee_id', $employee->id)->where('status', 'pending')
            ->whereDate('off_date', '>=', $from->toDateString())->whereDate('off_date', '<=', $to->toDateString())->exists()) {
            throw ValidationException::withMessages(['from_date' => 'A pending Comp Off request overlaps the selected dates. Resolve it before recording leave.']);
        }
    }

    private function assertNoOverlappingLeaveRequest(LeaveRequest $request): void
    {
        $this->assertNoOverlappingLeaveRequestForEmployee(
            $request->employee_id,
            CarbonImmutable::parse($request->from_date),
            CarbonImmutable::parse($request->to_date),
            $request->id,
        );
    }

    private function assertNoOverlappingLeaveRequestForEmployee(int $employeeId, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptRequestId = null): void
    {
        $overlapping = LeaveRequest::query()->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->when($exceptRequestId !== null, fn ($query) => $query->whereKeyNot($exceptRequestId))
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages(['from_date' => 'The selected dates overlap an existing pending or approved leave request.']);
        }
    }

    /**
     * Pending leave never changes attendance. On approval, only an existing Absent
     * row may be reconciled; all punch fields and linked biometric evidence remain
     * untouched, and the processor is prevented from replacing the approved status.
     */
    private function reconcileAbsentAttendanceForApprovedLeave(LeaveRequest $request, User $actor): void
    {
        $dates = $request->days()->where('counts_as_leave', true)->pluck('leave_date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())->unique()->values()->all();

        $this->reconcileApprovedLeaveDates($request->employee, $request->type, $dates, $request->reference, $actor, $request->id);
    }

    /** @param array<int, string> $dates */
    private function reconcileApprovedLeaveDates(Employee $employee, LeaveType $type, array $dates, string $reference, User $actor, ?int $requestId): void
    {
        $status = match ($type->code) {
            'annual' => AttendanceStatus::ApprovedLeave,
            'unpaid' => AttendanceStatus::UnpaidLeave,
            default => throw ValidationException::withMessages(['status' => 'This Leave Type cannot be applied to Attendance.']),
        };
        $dates = collect($dates)->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())->unique()->values();
        if ($dates->isEmpty()) {
            return;
        }

        $attendanceRows = EmployeeAttendance::query()->where('employee_id', $employee->id)
            ->where(function ($query) use ($dates): void {
                foreach ($dates as $date) {
                    $query->orWhereDate('attendance_date', $date);
                }
            })->lockForUpdate()->get();

        if ($attendanceRows->contains(fn (EmployeeAttendance $attendance): bool => $attendance->status !== AttendanceStatus::Absent)) {
            throw ValidationException::withMessages([
                'status' => 'Attendance changed after this request was recorded. Only Absent records can be reconciled; Present, Late, Half-Day, and other statuses were left unchanged.',
            ]);
        }

        foreach ($attendanceRows as $attendance) {
            $before = [
                'status' => $attendance->status->value,
                'is_overridden' => (bool) $attendance->is_overridden,
            ];
            $attendance->forceFill([
                'status' => $status,
                'is_overridden' => true,
            ])->save();

            AttendanceCorrection::query()->create([
                'employee_attendance_id' => $attendance->id,
                'actor_user_id' => $actor->id,
                'reason' => 'Attendance status reconciled when leave record '.$reference.' was approved.',
                'old_values' => $before,
                'new_values' => ['status' => $status->value, 'is_overridden' => true],
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
            $this->activity->log('attendance.leave_status_reconciled', $actor, $attendance, [
                'leave_reference' => $reference,
                'leave_request_id' => $requestId,
                'status' => $status->value,
                'changed_fields' => ['status'],
            ]);
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
