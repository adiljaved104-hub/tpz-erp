<?php

namespace App\Services\Hr;

use App\DTOs\Leave\AnnualLeaveBalance;
use App\DTOs\Leave\LeaveCycle;
use App\Models\Employee;
use App\Models\HistoricalLeaveRecord;
use App\Models\HistoricalLeaveRecordDay;
use App\Models\LeaveCycleAdjustment;
use App\Models\LeaveEntitlementAdjustment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequestDay;
use App\Services\Leave\AnnualLeaveBalanceCalculator;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class JoiningDateLeaveBalanceService
{
    public function __construct(
        private readonly AnnualLeaveCycleResolver $cycles,
        private readonly AnnualLeaveBalanceCalculator $calculator,
    ) {}

    public function forDate(Employee $employee, CarbonImmutable|string $date): AnnualLeaveBalance
    {
        $date = $date instanceof CarbonImmutable ? $date->startOfDay() : CarbonImmutable::parse($date)->startOfDay();
        $cycle = $this->cycles->forDate($employee, $date);
        $policy = $this->policyForCycle($cycle, $date);
        $totals = $this->totals($employee, $cycle);
        $adjustment = $this->adjustments($employee, $cycle);
        $carry = $this->carryForward($employee, $cycle, $policy, $date);
        $approved = bcadd($totals['approved'], $totals['historical_approved'], 2);
        $pending = bcadd($totals['pending'], $totals['historical_pending'], 2);
        $balance = $this->calculator->calculate((string) $policy->annual_leave_entitlement_days, $adjustment, $approved, $pending, $carry);

        return new AnnualLeaveBalance(
            entitlement: $balance->entitlement,
            adjustments: $balance->adjustments,
            used: $balance->used,
            pending: $balance->pending,
            remaining: $balance->remaining,
            availableAfterPending: $balance->availableAfterPending,
            periodStart: $cycle->start->toDateString(),
            periodEnd: $cycle->end->toDateString(),
            historicalUsed: $totals['historical_approved'],
            currentUsed: $totals['approved'],
            unpaidUsed: $totals['unpaid'],
            carryForward: $carry,
            policyId: $policy->id,
        );
    }

    /** @return array{approved:string,pending:string,historical_approved:string,historical_pending:string,unpaid:string} */
    private function totals(Employee $employee, LeaveCycle $cycle): array
    {
        $requestDays = LeaveRequestDay::query()->where('counts_as_leave', true)
            ->whereDate('leave_date', '>=', $cycle->start->toDateString())->whereDate('leave_date', '<=', $cycle->end->toDateString())
            ->whereHas('request', fn ($query) => $query->where('employee_id', $employee->id)->whereIn('status', ['approved', 'pending'])
                ->whereHas('type', fn ($types) => $types->where('consumes_annual_entitlement', true)));
        $approved = (string) (clone $requestDays)->whereHas('request', fn ($query) => $query->where('status', 'approved'))->sum('leave_units');
        $pending = (string) (clone $requestDays)->whereHas('request', fn ($query) => $query->where('status', 'pending'))->sum('leave_units');

        $historicalDays = HistoricalLeaveRecordDay::query()->where('counts_as_leave', true)
            ->whereDate('leave_date', '>=', $cycle->start->toDateString())->whereDate('leave_date', '<=', $cycle->end->toDateString())
            ->whereHas('record', fn ($query) => $query->where('employee_id', $employee->id)->whereIn('approval_status', ['approved', 'pending'])
                ->whereHas('type', fn ($types) => $types->where('consumes_annual_entitlement', true)));
        $historicalApproved = (string) (clone $historicalDays)->whereHas('record', fn ($query) => $query->where('approval_status', 'approved'))->sum('leave_units');
        $historicalPending = (string) (clone $historicalDays)->whereHas('record', fn ($query) => $query->where('approval_status', 'pending'))->sum('leave_units');

        $manualRecords = HistoricalLeaveRecord::query()->where('employee_id', $employee->id)
            ->where('calculation_method', 'manual_verified')->whereDate('leave_period_start', $cycle->start->toDateString())
            ->whereHas('type', fn ($types) => $types->where('consumes_annual_entitlement', true));
        $historicalApproved = bcadd($historicalApproved, (string) (clone $manualRecords)->where('approval_status', 'approved')->sum('chargeable_days'), 2);
        $historicalPending = bcadd($historicalPending, (string) (clone $manualRecords)->where('approval_status', 'pending')->sum('chargeable_days'), 2);

        $unpaidRequestDays = LeaveRequestDay::query()->where('counts_as_leave', true)
            ->whereDate('leave_date', '>=', $cycle->start->toDateString())->whereDate('leave_date', '<=', $cycle->end->toDateString())
            ->whereHas('request', fn ($query) => $query->where('employee_id', $employee->id)->where('status', 'approved')
                ->whereHas('type', fn ($types) => $types->where('code', 'unpaid')))->sum('leave_units');
        $unpaidHistoricalDays = HistoricalLeaveRecordDay::query()->where('counts_as_leave', true)
            ->whereDate('leave_date', '>=', $cycle->start->toDateString())->whereDate('leave_date', '<=', $cycle->end->toDateString())
            ->whereHas('record', fn ($query) => $query->where('employee_id', $employee->id)->where('approval_status', 'approved')
                ->whereHas('type', fn ($types) => $types->where('code', 'unpaid')))->sum('leave_units');
        $manualUnpaid = HistoricalLeaveRecord::query()->where('employee_id', $employee->id)
            ->where('calculation_method', 'manual_verified')->whereDate('leave_period_start', $cycle->start->toDateString())
            ->where('approval_status', 'approved')->whereHas('type', fn ($types) => $types->where('code', 'unpaid'))->sum('chargeable_days');

        return [
            'approved' => bcadd((string) $approved, '0.00', 2), 'pending' => bcadd((string) $pending, '0.00', 2),
            'historical_approved' => bcadd((string) $historicalApproved, '0.00', 2), 'historical_pending' => bcadd((string) $historicalPending, '0.00', 2),
            'unpaid' => bcadd(bcadd((string) $unpaidRequestDays, (string) $unpaidHistoricalDays, 2), (string) $manualUnpaid, 2),
        ];
    }

    private function adjustments(Employee $employee, LeaveCycle $cycle): string
    {
        $credits = LeaveCycleAdjustment::query()->where('employee_id', $employee->id)->whereDate('leave_period_start', $cycle->start->toDateString())->where('adjustment_type', 'credit')->sum('days');
        $debits = LeaveCycleAdjustment::query()->where('employee_id', $employee->id)->whereDate('leave_period_start', $cycle->start->toDateString())->where('adjustment_type', 'debit')->sum('days');

        // Legacy adjustments only store a calendar year. Attribute each legacy entry
        // once to the anniversary cycle that began in that recorded year.
        $legacy = LeaveEntitlementAdjustment::query()->where('employee_id', $employee->id)
            ->where('leave_year', $cycle->start->year)->sum('days');

        return bcadd(bcsub((string) $credits, (string) $debits, 2), (string) $legacy, 2);
    }

    public function policyForCycle(LeaveCycle $cycle, ?CarbonImmutable $asOf = null): LeavePolicy
    {
        $asOf ??= $cycle->end;
        $asOf = $asOf->min($cycle->end);
        $policies = LeavePolicy::query()->where('status', true)->whereDate('effective_from', '<=', $asOf->toDateString())->orderBy('effective_from')->get();
        $policy = $policies->filter(fn (LeavePolicy $candidate): bool => $candidate->effective_from->lte($cycle->start))->last();
        $fullCycleVersion = $policies->filter(fn (LeavePolicy $candidate): bool => $candidate->effective_from->gt($cycle->start)
            && $candidate->effective_from->lte($asOf) && $candidate->cycle_transition_decision === 'apply_full_entitlement')->last();
        $policy = $fullCycleVersion ?? $policy;
        // Historical cycles before the ERP's first recorded policy use that earliest
        // recorded entitlement as the baseline; no future version is applied mid-cycle.
        $policy ??= LeavePolicy::query()->where('status', true)->orderBy('effective_from')->first();
        if (! $policy instanceof LeavePolicy) {
            throw ValidationException::withMessages(['leave_policy' => 'No effective Leave Policy is available for this employee leave cycle.']);
        }

        return $policy;
    }

    private function carryForward(Employee $employee, LeaveCycle $cycle, LeavePolicy $policy, CarbonImmutable $asOf): string
    {
        if (! $policy->carry_forward_enabled || $cycle->completedYears === 0 || $policy->carry_forward_expiry_months === null
            || bccomp((string) $policy->maximum_carry_forward_days, '0.00', 2) <= 0) {
            return '0.00';
        }
        $previousDate = $cycle->start->subDay();
        // Enabling carry-forward never backfills balances that expired before this version became effective.
        if ($previousDate->lt($policy->effective_from)) {
            return '0.00';
        }
        if ($asOf->gte($cycle->start->addMonthsNoOverflow((int) $policy->carry_forward_expiry_months))) {
            return '0.00';
        }

        $previousCycle = $this->cycles->forDate($employee, $previousDate);
        $previousPolicy = $this->policyForCycle($previousCycle, $previousCycle->end);
        $previousTotals = $this->totals($employee, $previousCycle);
        $unused = bcsub(
            bcadd((string) $previousPolicy->annual_leave_entitlement_days, $this->adjustments($employee, $previousCycle), 2),
            bcadd(bcadd($previousTotals['approved'], $previousTotals['historical_approved'], 2), bcadd($previousTotals['pending'], $previousTotals['historical_pending'], 2), 2),
            2,
        );
        if (bccomp($unused, '0.00', 2) <= 0) {
            return '0.00';
        }

        return bccomp($unused, (string) $policy->maximum_carry_forward_days, 2) > 0
            ? (string) $policy->maximum_carry_forward_days
            : bcadd($unused, '0.00', 2);
    }
}
