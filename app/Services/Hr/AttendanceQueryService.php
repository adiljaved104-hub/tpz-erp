<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Models\AttendancePolicy;
use App\Models\EmployeeAttendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AttendanceQueryService
{
    public function __construct(
        private readonly HrScopeService $scope,
        private readonly AttendancePenaltyCalculator $penalties,
    ) {}

    public function query(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId = null, ?int $teamId = null): Builder
    {
        return $this->scope->attendanceQuery($user)
            ->with(['employee:id,employee_id,name,team_id', 'policy:id,late_occurrences_for_penalty,absence_equivalent_penalty_days', 'schedule:id,timezone'])
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->when($employeeId, fn (Builder $query): Builder => $query->where('employee_id', $employeeId))
            ->when($teamId, fn (Builder $query): Builder => $query->whereHas('employee', fn (Builder $employees): Builder => $employees->where('team_id', $teamId)))
            ->latest('attendance_date')
            ->latest('id');
    }

    /** @return array{present:int,late:int,absent:int,approved_leave:int,comp_off:int,early_checkout:int,early_checkout_minutes:int,missing_checkout:int,late_penalty:string} */
    public function summary(Builder $query): array
    {
        $counts = (clone $query)->reorder()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $lateRows = (clone $query)->reorder()->where('status', AttendanceStatus::Late->value)
            ->get(['attendance_date', 'attendance_policy_id']);
        $policyIds = $lateRows->pluck('attendance_policy_id')->filter()->unique();
        $policies = AttendancePolicy::query()->whereKey($policyIds)->get()->keyBy('id');
        $fallback = AttendancePolicy::query()->where('status', true)->latest('effective_from')->first();
        $penalty = '0.00';
        $earlyCheckout = (clone $query)->reorder()->where('early_departure_minutes', '>', 0)->count();
        $earlyCheckoutMinutes = (int) (clone $query)->reorder()->sum('early_departure_minutes');
        $missingCheckout = (clone $query)->reorder()
            ->whereNotNull('first_check_in_at')
            ->whereNull('last_check_out_at')
            ->whereNotIn('status', [
                AttendanceStatus::ApprovedLeave->value,
                AttendanceStatus::UnpaidLeave->value,
                AttendanceStatus::PublicHoliday->value,
                AttendanceStatus::WeekendOff->value,
                AttendanceStatus::CompensatoryOff->value,
            ])->count();

        $lateRows->groupBy(fn (EmployeeAttendance $row): string => $row->attendance_date->format('Y-m').':'.($row->attendance_policy_id ?? 'default'))
            ->each(function ($rows) use (&$penalty, $policies, $fallback): void {
                $policy = $policies->get($rows->first()->attendance_policy_id) ?? $fallback;
                if ($policy === null) {
                    return;
                }
                $result = $this->penalties->calculate($rows->count(), (int) $policy->late_occurrences_for_penalty, (string) $policy->absence_equivalent_penalty_days);
                $penalty = bcadd($penalty, $result['absence_equivalent_penalty_days'], 2);
            });

        return [
            'present' => (int) ($counts[AttendanceStatus::Present->value] ?? 0),
            'late' => (int) ($counts[AttendanceStatus::Late->value] ?? 0),
            'absent' => (int) ($counts[AttendanceStatus::Absent->value] ?? 0),
            'approved_leave' => (int) ($counts[AttendanceStatus::ApprovedLeave->value] ?? 0) + (int) ($counts[AttendanceStatus::UnpaidLeave->value] ?? 0),
            'comp_off' => (int) ($counts[AttendanceStatus::CompensatoryOff->value] ?? 0),
            'early_checkout' => $earlyCheckout,
            'early_checkout_minutes' => $earlyCheckoutMinutes,
            'missing_checkout' => $missingCheckout,
            'late_penalty' => $penalty,
        ];
    }

    /** @return array{present:int,late:int,absent:int} */
    public function uniqueEmployeeSummary(Builder $query): array
    {
        $counts = (clone $query)->reorder()
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN status = ? THEN employee_id END) AS present_employees,
                COUNT(DISTINCT CASE WHEN status = ? THEN employee_id END) AS late_employees,
                COUNT(DISTINCT CASE WHEN status = ? THEN employee_id END) AS absent_employees',
                [
                    AttendanceStatus::Present->value,
                    AttendanceStatus::Late->value,
                    AttendanceStatus::Absent->value,
                ],
            )
            ->first();

        return [
            'present' => (int) ($counts?->present_employees ?? 0),
            'late' => (int) ($counts?->late_employees ?? 0),
            'absent' => (int) ($counts?->absent_employees ?? 0),
        ];
    }
}
