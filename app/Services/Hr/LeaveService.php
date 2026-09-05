<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
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
        $validated = Validator::make($data, [
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $type = LeaveType::query()->whereKey($validated['leave_type_id'])->where('status', true)->firstOrFail();
        if (! in_array($type->code, ['annual', 'unpaid'], true)) {
            throw ValidationException::withMessages(['leave_type_id' => 'This Leave Type is not available in the B1 request workflow.']);
        }
        $from = CarbonImmutable::parse($validated['from_date']);
        $to = CarbonImmutable::parse($validated['to_date']);
        $policy = $this->policyFor($from);
        $calculation = $this->days->calculate($employee, $from, $to);
        $reference = $this->references->nextLeaveRequestReference((int) $from->format('Y'));

        $request = DB::transaction(function () use ($validated, $employee, $type, $policy, $calculation, $reference, $actor): LeaveRequest {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($type->consumes_annual_entitlement) {
                $balance = $this->balances->for($employee, (int) CarbonImmutable::parse($validated['from_date'])->format('Y'));
                if (bccomp($calculation['working_days'], $balance->availableAfterPending, 2) > 0) {
                    throw ValidationException::withMessages(['fromDate' => 'The request exceeds the Annual Leave available after pending requests.']);
                }
            }

            $request = LeaveRequest::query()->create([
                'reference' => $reference,
                'employee_id' => $employee->id,
                'team_id_at_request' => $employee->team_id,
                'leave_type_id' => $type->id,
                'leave_policy_id' => $policy->id,
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'requested_working_days' => $calculation['working_days'],
                'annual_entitlement_snapshot' => $type->consumes_annual_entitlement ? $policy->annual_leave_entitlement_days : null,
                'status' => 'pending',
                'reason' => trim($validated['reason']),
                'submitted_at' => now(),
                'idempotency_key' => (string) Str::uuid(),
            ]);
            foreach ($calculation['days'] as $day) {
                LeaveRequestDay::query()->create($day + ['leave_request_id' => $request->id]);
            }
            $this->event($request, 'submitted', null, 'pending', $actor);
            $this->activity->log('leave.submitted', $actor, $request, ['reference' => $reference, 'working_days' => $calculation['working_days']]);

            return $request;
        });
        $this->notifications->submitted($request);

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

    private function event(LeaveRequest $request, string $type, ?string $from, string $to, User $actor, ?string $reason = null): void
    {
        LeaveRequestEvent::query()->create([
            'leave_request_id' => $request->id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $actor->id, 'reason' => $reason, 'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now(), 'created_at' => now(),
        ]);
    }
}
