<?php

namespace App\Services\Hr;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeeJoiningDateAudit;
use App\Models\HistoricalLeaveRecord;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JoiningDateCorrectionService
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly HrScopeService $scope,
        private readonly AnnualLeaveCycleResolver $cycles,
        private readonly JoiningDateLeaveBalanceService $balances,
        private readonly ActivityLogger $activity,
    ) {}

    public function employeeQuery(User $actor): Builder
    {
        if (! $this->authorization->allows($actor, HrPermission::LeaveJoiningDateCorrect)) {
            return Employee::query()->whereRaw('1 = 0');
        }

        // Activation requires every existing employee, including inactive ones.
        // Other actors retain the existing leave employee scope unchanged.
        if (in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && $this->authorization->allows($actor, HrPermission::LeaveViewAll)) {
            return Employee::query();
        }

        return $this->scope->employeeQuery($actor, attendance: false);
    }

    public function canVerifyEmployee(User $actor, Employee $employee): bool
    {
        return $this->employeeQuery($actor)->whereKey($employee->getKey())->exists();
    }

    /** @return array<string, mixed> */
    public function preview(Employee $employee, string $joiningDate, User $actor): array
    {
        $this->authorize($actor, $employee);
        $date = CarbonImmutable::parse($joiningDate)->startOfDay();
        if ($date->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['joining_date' => 'The actual joining date cannot be in the future.']);
        }
        $oldPeriod = null;
        $oldBalance = null;
        if ($employee->joining_date !== null && $employee->joining_date_verified_at !== null) {
            try {
                $old = $this->cycles->forDate($employee, CarbonImmutable::today());
                $oldPeriod = [
                    'start' => $old->start->toDateString(),
                    'end' => $old->end->toDateString(),
                    'next_anniversary' => $old->end->addDay()->toDateString(),
                ];
                $oldBalance = $this->balanceSnapshot($employee);
            } catch (ValidationException) {
                $oldPeriod = ['requires_owner_anniversary_decision' => true];
            }
        }
        $candidate = clone $employee;
        $candidate->setAttribute('joining_date', $date->toDateString());
        $candidate->setAttribute('joining_date_verified_at', now());
        try {
            $new = $this->cycles->forDate($candidate, CarbonImmutable::today());
            $newPeriod = [
                'start' => $new->start->toDateString(),
                'end' => $new->end->toDateString(),
                'next_anniversary' => $new->end->addDay()->toDateString(),
            ];
            $newBalance = $this->balanceSnapshot($candidate);
        } catch (ValidationException $exception) {
            $newPeriod = ['error' => collect($exception->errors())->flatten()->first()];
            $newBalance = ['unavailable' => collect($exception->errors())->flatten()->first()];
        }

        $requests = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['approved', 'pending'])
            ->count();
        $historical = HistoricalLeaveRecord::query()->where('employee_id', $employee->id)
            ->whereIn('approval_status', ['approved', 'pending'])->count();

        return [
            'employee_id' => $employee->employee_id,
            'employee_name' => $employee->name,
            'previous_joining_date' => $employee->joining_date?->toDateString(),
            'corrected_joining_date' => $date->toDateString(),
            'previous_verified' => $employee->joining_date_verified_at !== null,
            'old_current_period' => $oldPeriod,
            'new_current_period' => $newPeriod,
            'old_current_balance' => $oldBalance,
            'new_current_balance' => $newBalance,
            'existing_approved_or_pending_leave_requests' => $requests,
            'existing_approved_or_pending_historical_records' => $historical,
            'existing_leave_records_will_not_be_rewritten' => true,
        ];
    }

    public function verifyOrCorrect(Employee $employee, array $data, User $actor): Employee
    {
        $this->authorize($actor, $employee);
        $validated = Validator::make($data, [
            'joining_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
            'confirmed' => ['required', 'accepted'],
        ])->validate();

        return DB::transaction(function () use ($employee, $validated, $actor): Employee {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $preview = $this->preview($locked, $validated['joining_date'], $actor);
            $newDate = CarbonImmutable::parse($validated['joining_date'])->toDateString();
            $previousDate = $locked->joining_date?->toDateString();
            $action = $locked->joining_date_verified_at === null ? 'verified' : 'corrected';

            EmployeeJoiningDateAudit::query()->create([
                'employee_id' => $locked->id,
                'previous_joining_date' => $previousDate,
                'corrected_joining_date' => $newDate,
                'actor_user_id' => $actor->id,
                'reason' => trim($validated['reason']),
                'affected_leave_periods' => $preview,
                'occurred_at' => now(),
                'action' => $action,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $locked->forceFill([
                'joining_date' => $newDate,
                'joining_date_verified_at' => now(),
                'joining_date_verified_by_user_id' => $actor->id,
            ])->save();
            $this->activity->log('hr.employee_joining_date_'.$action, $actor, $locked, [
                'previous_joining_date' => $previousDate, 'corrected_joining_date' => $newDate,
                'reason' => trim($validated['reason']), 'affected_leave_records_preview' => $preview,
            ]);

            return $locked->refresh();
        });
    }

    private function authorize(User $actor, Employee $employee): void
    {
        throw_unless($this->canVerifyEmployee($actor, $employee), AuthorizationException::class);
    }

    /** @return array{entitlement:string,used:string,pending:string,remaining:string,available:string,available_after_pending:string}|array{unavailable:string} */
    private function balanceSnapshot(Employee $employee): array
    {
        try {
            $balance = $this->balances->forDate($employee, CarbonImmutable::today());

            return [
                'entitlement' => $balance->entitlement,
                'used' => $balance->used,
                'pending' => $balance->pending,
                'remaining' => $balance->remaining,
                'available' => $balance->availableAfterPending,
                'available_after_pending' => $balance->availableAfterPending,
            ];
        } catch (ValidationException $exception) {
            return ['unavailable' => collect($exception->errors())->flatten()->first()];
        }
    }
}
