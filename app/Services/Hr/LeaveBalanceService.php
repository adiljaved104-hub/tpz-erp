<?php

namespace App\Services\Hr;

use App\DTOs\Leave\AnnualLeaveBalance;
use App\Models\Employee;
use App\Models\LeaveEntitlementAdjustment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequestDay;
use App\Services\Leave\AnnualLeaveBalanceCalculator;
use Carbon\CarbonImmutable;

class LeaveBalanceService
{
    public function __construct(private readonly AnnualLeaveBalanceCalculator $calculator) {}

    public function for(Employee $employee, ?int $year = null): AnnualLeaveBalance
    {
        $year ??= (int) now()->format('Y');
        $today = CarbonImmutable::today();
        $date = $year === (int) $today->format('Y') ? $today : CarbonImmutable::create($year, 12, 31);
        $policy = LeavePolicy::query()->where('status', true)->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->latest('effective_from')->firstOrFail();
        $adjustments = (string) LeaveEntitlementAdjustment::query()->where('employee_id', $employee->id)->where('leave_year', $year)->sum('days');

        return $this->calculator->calculate(
            (string) $policy->annual_leave_entitlement_days,
            $adjustments,
            $this->used($employee, $year, 'approved'),
            $this->used($employee, $year, 'pending'),
        );
    }

    private function used(Employee $employee, int $year, string $status): string
    {
        return (string) LeaveRequestDay::query()
            ->where('counts_as_leave', true)
            ->whereYear('leave_date', $year)
            ->whereHas('request', fn ($query) => $query->where('employee_id', $employee->id)->where('status', $status)
                ->whereHas('type', fn ($types) => $types->where('consumes_annual_entitlement', true)))
            ->sum('leave_units');
    }
}
