<?php

namespace App\Services\Hr;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyEvent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HistoricalLeaveActivationService
{
    public function __construct(
        private readonly HistoricalLeaveAuthorization $scope,
        private readonly HrAuthorization $authorization,
        private readonly JoiningDateLeaveBalanceService $balances,
        private readonly ActivityLogger $activity,
    ) {}

    public function isActive(): bool
    {
        return DB::table('historical_leave_workflow_activations')->where('workflow', 'annual_leave')->exists();
    }

    public function hasAnyActivation(): bool
    {
        return $this->isActive() || DB::table('employee_annual_leave_activations')->exists();
    }

    public function isActiveFor(Employee $employee, ?bool $reviewed = null): bool
    {
        $reviewed ??= $this->isReviewed($employee);
        if (! $reviewed) {
            return false;
        }
        // Keep approvals made under the earlier company-wide workflow valid.
        if ($this->isActive()) {
            return true;
        }
        $approval = DB::table('employee_annual_leave_activations')->where('employee_id', $employee->id)
            ->orderByDesc('id')->first(['review_fingerprint']);

        return $approval !== null
            && hash_equals($approval->review_fingerprint, $this->onboardingFingerprint($employee));
    }

    public function employeeStatus(Employee $employee): string
    {
        if ($employee->joining_date === null || $employee->joining_date_verified_at === null) {
            return 'Not Verified';
        }
        $reviewed = $this->isReviewed($employee);
        if (! $reviewed) {
            return 'Pending HR Review';
        }

        return $this->isActiveFor($employee, $reviewed) ? 'Active' : 'Ready for Activation';
    }

    public function assertAnnualRequestsActive(Employee $employee): void
    {
        if ($employee->joining_date === null || $employee->joining_date_verified_at === null) {
            throw ValidationException::withMessages([
                'joining_date' => 'The employee joining date must be verified by HR before Annual Leave is available.',
            ]);
        }

        if (! $this->isReviewed($employee)) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'HR must verify this employee’s joining date and historical leave balance before Annual Leave is available.',
            ]);
        }

        if (! $this->isActiveFor($employee)) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'Annual Leave is waiting for Owner/Admin activation for this employee.',
            ]);
        }
    }

    /** @return array{total:int,reviewed:int,pending:int,activated:int,active:bool} */
    public function status(): array
    {
        $total = 0;
        $reviewed = 0;
        $activated = 0;
        Employee::query()->orderBy('id')->chunkById(100, function ($employees) use (&$total, &$reviewed, &$activated): void {
            foreach ($employees as $employee) {
                $total++;
                $isReviewed = $this->isReviewed($employee);
                $reviewed += $isReviewed ? 1 : 0;
                $activated += $this->isActiveFor($employee, $isReviewed) ? 1 : 0;
            }
        });

        return ['total' => $total, 'reviewed' => $reviewed, 'pending' => $total - $reviewed,
            'activated' => $activated, 'active' => $activated > 0];
    }

    public function isReviewed(Employee $employee): bool
    {
        if ($employee->joining_date === null || $employee->joining_date_verified_at === null) {
            return false;
        }
        $latest = DB::table('historical_leave_reconciliation_reviews')->where('employee_id', $employee->id)
            ->orderByDesc('id')->first();

        if ($latest === null) {
            return false;
        }

        // Ordinary requests and policy changes must invalidate a pre-activation
        // review, but must not force a new HR review after every normal leave event.
        return ($this->isActive() || DB::table('employee_annual_leave_activations')->where('employee_id', $employee->id)->exists())
            ? hash_equals($latest->onboarding_fingerprint, $this->onboardingFingerprint($employee))
            : hash_equals($latest->data_fingerprint, $this->activationFingerprint($employee));
    }

    /** @param array<int, int> $employeeIds */
    public function activateEmployees(array $employeeIds, User $actor): void
    {
        if (! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            || ! $this->authorization->allows($actor, HrPermission::LeaveManagePolicy)) {
            throw new AuthorizationException;
        }
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($ids === [] || count($ids) > 100 || count($ids) !== count($employeeIds) || min($ids) < 1) {
            throw ValidationException::withMessages(['activation' => 'Select up to 100 different employees for activation.']);
        }

        DB::transaction(function () use ($ids, $actor): void {
            $employees = Employee::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($employees->count() !== count($ids)) {
                throw ValidationException::withMessages(['activation' => 'An employee could not be found.']);
            }
            foreach ($employees as $employee) {
                if ($this->isActiveFor($employee)) {
                    throw ValidationException::withMessages(['activation' => 'Annual Leave is already active for '.$employee->employee_id.'.']);
                }
                if (! $this->isReviewed($employee)) {
                    throw ValidationException::withMessages(['activation' => 'HR must review the current leave balance for '.$employee->employee_id.'.']);
                }
                $review = DB::table('historical_leave_reconciliation_reviews')->where('employee_id', $employee->id)
                    ->orderByDesc('id')->first();
                $balance = get_object_vars($this->balances->forDate($employee, now()->toDateString()));
                $policy = LeavePolicy::query()->findOrFail($balance['policyId']);
                if (json_decode($review->balance_snapshot, true, 512, JSON_THROW_ON_ERROR) != $balance
                    || bccomp((string) $balance['entitlement'], '12.00', 2) !== 0
                    || $policy->monthly_accrual_enabled || $policy->carry_forward_enabled
                    || bccomp((string) $balance['carryForward'], '0.00', 2) !== 0) {
                    throw ValidationException::withMessages(['activation' => 'Review the 12-day balance for '.$employee->employee_id.' again before activation.']);
                }
                DB::table('employee_annual_leave_activations')->insert([
                    'employee_id' => $employee->id, 'review_id' => $review->id,
                    'approved_by_user_id' => $actor->id, 'review_fingerprint' => $review->onboarding_fingerprint,
                    'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->activity->log('hr.employee_annual_leave_activated', $actor, $employee, [
                    'employee_id' => $employee->id, 'review_id' => $review->id,
                    'period_start' => $balance['periodStart'], 'period_end' => $balance['periodEnd'],
                ]);
            }
        }, 3);
    }

    public function review(Employee $employee, User $actor): void
    {
        if (! $this->authorization->allows($actor, HrPermission::LeaveHistoricalView)
            || ! $this->authorization->allows($actor, HrPermission::LeaveHistoricalRecord)
            || ! $this->scope->employeeQuery($actor, HrPermission::LeaveHistoricalView)->whereKey($employee->id)->exists()) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($employee, $actor): void {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($locked->joining_date === null || $locked->joining_date_verified_at === null) {
                throw ValidationException::withMessages(['employee_id' => 'Verify the joining date before confirming the leave balance.']);
            }
            $balance = $this->balances->forDate($locked, now()->toDateString());
            DB::table('historical_leave_reconciliation_reviews')->insert([
                'employee_id' => $locked->id,
                'reviewed_by_user_id' => $actor->id,
                'data_fingerprint' => $this->activationFingerprint($locked),
                'onboarding_fingerprint' => $this->onboardingFingerprint($locked),
                'balance_snapshot' => json_encode(get_object_vars($balance), JSON_THROW_ON_ERROR),
                'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity->log('hr.historical_leave_reconciliation_reviewed', $actor, $locked, [
                'employee_id' => $locked->id, 'period_start' => $balance->periodStart,
                'period_end' => $balance->periodEnd,
            ]);
        }, 3);
    }

    public function activate(User $actor): void
    {
        if (! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            || ! $this->authorization->allows($actor, HrPermission::LeaveManagePolicy)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor): void {
            if (DB::table('employee_annual_leave_activations')->exists()) {
                throw ValidationException::withMessages(['activation' => 'Individual employee activation has begun. Company-wide activation is unavailable.']);
            }
            if ($this->isActive()) {
                throw ValidationException::withMessages(['activation' => 'Annual Leave is already active.']);
            }
            $employees = Employee::query()->orderBy('id')->lockForUpdate()->get();
            if ($employees->isEmpty()) {
                throw ValidationException::withMessages(['activation' => 'No employees exist for reconciliation.']);
            }
            foreach ($employees as $employee) {
                if (! $this->isReviewed($employee)) {
                    throw ValidationException::withMessages([
                        'activation' => 'Every existing employee needs a verified joining date and current HR reconciliation review.',
                    ]);
                }
                $latest = DB::table('historical_leave_reconciliation_reviews')
                    ->where('employee_id', $employee->id)->orderByDesc('id')->first();
                $currentBalance = get_object_vars($this->balances->forDate($employee, now()->toDateString()));
                if (json_decode($latest->balance_snapshot, true, 512, JSON_THROW_ON_ERROR) != $currentBalance) {
                    throw ValidationException::withMessages([
                        'activation' => 'An employee leave balance changed after HR review. Review that employee again before activation.',
                    ]);
                }
            }
            $this->activateAnniversaryPolicy($actor);
            DB::table('historical_leave_workflow_activations')->insert([
                'workflow' => 'annual_leave', 'approved_by_user_id' => $actor->id,
                'reviewed_employee_count' => $employees->count(), 'activated_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity->log('hr.historical_leave_workflow_activated', $actor, $actor->employee, [
                'reviewed_employee_count' => $employees->count(),
            ]);
        }, 3);
    }

    private function activateAnniversaryPolicy(User $actor): void
    {
        $today = now()->toDateString();
        $current = LeavePolicy::query()->where('status', true)->whereNull('effective_to')
            ->lockForUpdate()->latest('effective_from')->first();
        if ($current === null || $current->effective_from->toDateString() >= $today
            || LeavePolicy::query()->where('status', true)->whereDate('effective_from', '>', $today)->exists()) {
            throw ValidationException::withMessages([
                'activation' => 'Review the effective-dated Leave Policy history before activating the anniversary policy.',
            ]);
        }
        if ($current->leave_year_basis === 'joining_anniversary') {
            return;
        }

        $current->forceFill(['effective_to' => now()->subDay()->toDateString()])->save();
        $policy = LeavePolicy::query()->create([
            'name' => 'Joining Anniversary Annual Leave Policy',
            'annual_leave_entitlement_days' => '12.00',
            'leave_year_mode' => $current->leave_year_mode,
            'leave_year_basis' => 'joining_anniversary',
            'monthly_accrual_enabled' => false,
            'carry_forward_enabled' => false,
            'maximum_carry_forward_days' => '0.00',
            'carry_forward_expiry_months' => null,
            'policy_description' => 'Annual entitlement follows the verified joining anniversary. No monthly accrual or carry-forward.',
            'policy_change_reason' => 'Owner/Admin approved historical reconciliation and initial anniversary policy activation.',
            'cycle_transition_decision' => 'next_anniversary',
            'half_day_leave_enabled' => $current->half_day_leave_enabled,
            'manager_approval_enabled' => $current->manager_approval_enabled,
            'compensatory_off_requires_approval' => $current->compensatory_off_requires_approval,
            'compensatory_off_expiry_days' => $current->compensatory_off_expiry_days,
            'scheduled_sunday_compensatory_off_enabled' => $current->scheduled_sunday_compensatory_off_enabled,
            'effective_from' => $today,
            'effective_to' => null,
            'status' => true,
            'created_by_user_id' => $actor->id,
        ]);
        LeavePolicyEvent::query()->create([
            'leave_policy_id' => $policy->id,
            'actor_user_id' => $actor->id,
            'event_type' => 'initial_anniversary_policy',
            'effective_date' => $today,
            'previous_values' => [
                'policy_id' => $current->id,
                'annual_entitlement_days' => (string) $current->annual_leave_entitlement_days,
                'leave_year_basis' => $current->leave_year_basis,
            ],
            'new_values' => [
                'policy_id' => $policy->id,
                'annual_entitlement_days' => '12.00',
                'leave_year_basis' => 'joining_anniversary',
                'monthly_accrual_enabled' => false,
                'carry_forward_enabled' => false,
            ],
            'reason' => 'Owner/Admin approved historical reconciliation; existing leave requests and attendance are preserved.',
            'occurred_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function onboardingFingerprint(Employee $employee): string
    {
        $records = DB::table('historical_leave_records')->where('employee_id', $employee->id)
            ->orderBy('id')->get(['id', 'leave_type_id', 'from_date', 'to_date', 'chargeable_days', 'approval_status', 'remarks', 'updated_at']);
        $days = DB::table('historical_leave_record_days')->whereIn('historical_leave_record_id', $records->pluck('id'))
            ->orderBy('id')->get(['id', 'historical_leave_record_id', 'leave_date', 'counts_as_leave', 'leave_units', 'updated_at']);
        $adjustments = DB::table('leave_cycle_adjustments')->where('employee_id', $employee->id)
            ->orderBy('id')->get(['id', 'adjustment_type', 'days', 'leave_period_start', 'leave_period_end', 'updated_at']);
        $legacyAdjustments = DB::table('leave_entitlement_adjustments')->where('employee_id', $employee->id)
            ->orderBy('id')->get(['id', 'leave_year', 'days', 'updated_at']);

        return hash('sha256', json_encode([
            'joining_date' => $employee->joining_date?->toDateString(),
            'verified_at' => $employee->joining_date_verified_at?->toISOString(),
            'joining_date_audit_id' => DB::table('employee_joining_date_audits')
                ->where('employee_id', $employee->id)->max('id'),
            'records' => $records->all(), 'historical_days' => $days->all(),
            'adjustments' => $adjustments->all(), 'legacy_adjustments' => $legacyAdjustments->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function activationFingerprint(Employee $employee): string
    {
        $requests = DB::table('leave_requests')->where('employee_id', $employee->id)
            ->orderBy('id')->get(['id', 'leave_type_id', 'leave_policy_id', 'status', 'from_date', 'to_date',
                'requested_working_days', 'annual_entitlement_snapshot', 'updated_at']);
        $days = DB::table('leave_request_days')->whereIn('leave_request_id', $requests->pluck('id'))
            ->orderBy('id')->get(['id', 'leave_request_id', 'leave_date', 'counts_as_leave', 'leave_units', 'updated_at']);
        $policies = DB::table('leave_policies')->where('status', true)->orderBy('id')
            ->get(['id', 'annual_leave_entitlement_days', 'leave_year_basis', 'effective_from', 'effective_to',
                'status', 'carry_forward_enabled', 'maximum_carry_forward_days', 'carry_forward_expiry_months',
                'cycle_transition_decision', 'updated_at']);

        return hash('sha256', json_encode([
            'onboarding' => $this->onboardingFingerprint($employee),
            'requests' => $requests->all(), 'request_days' => $days->all(), 'policies' => $policies->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
