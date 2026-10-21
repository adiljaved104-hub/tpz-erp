<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\HrPermission;
use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\HistoricalLeaveRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Read-only validation and balance-impact preview for a historical leave entry. */
class HistoricalLeavePreviewService
{
    public function __construct(
        private readonly HistoricalLeaveAuthorization $access,
        private readonly HrAuthorization $authorization,
        private readonly HrScopeService $scope,
        private readonly LeaveDayCalculator $days,
        private readonly AnnualLeaveCycleResolver $cycles,
        private readonly JoiningDateLeaveBalanceService $balances,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preview(array $input, User $actor, HrPermission $requiredPermission = HrPermission::LeaveHistoricalRecord): array
    {
        $data = Validator::make($input, [
            'employee_id' => ['required', 'integer'], 'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'], 'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'chargeable_days' => ['nullable', 'numeric', 'gt:0', 'max:366'],
            'manual_override_reason' => ['nullable', 'string', 'max:2000'],
            'approval_status' => ['required', 'in:pending,approved,rejected,cancelled'],
        ])->validate();

        $employee = $this->access->employeeQuery($actor, $requiredPermission, excludeSelf: true)
            ->whereKey($data['employee_id'])->first();
        throw_unless($employee instanceof Employee, AuthorizationException::class);
        if ($employee->joining_date === null || $employee->joining_date_verified_at === null) {
            throw ValidationException::withMessages(['employee_id' => 'The actual joining date must be verified before leave periods or balances can be previewed.']);
        }
        $type = LeaveType::query()->whereKey($data['leave_type_id'])->where('status', true)->firstOrFail();
        if (! in_array($type->code, ['annual', 'unpaid'], true)) {
            throw ValidationException::withMessages(['leave_type_id' => 'Historical leave supports Annual and Unpaid Leave only.']);
        }
        $from = CarbonImmutable::parse($data['from_date'])->startOfDay();
        $to = CarbonImmutable::parse($data['to_date'])->startOfDay();
        if ($from->lt(CarbonImmutable::parse($employee->joining_date)->startOfDay())) {
            throw ValidationException::withMessages(['from_date' => 'Dates cannot be earlier than the verified original joining date.']);
        }
        if ($to->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['to_date' => 'Historical leave dates cannot be in the future.']);
        }
        $overlap = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())->exists()
            || HistoricalLeaveRecord::query()->where('employee_id', $employee->id)->whereIn('approval_status', ['pending', 'approved'])
                ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['from_date' => 'The selected dates overlap pending or approved ERP/historical leave.']);
        }

        $manual = false;
        try {
            $calculation = $this->days->calculate($employee, $from, $to);
            $days = $calculation['days'];
            $chargeable = $calculation['working_days'];
            if (filled($data['chargeable_days'] ?? null)
                && bccomp((string) $data['chargeable_days'], (string) $chargeable, 3) !== 0) {
                throw ValidationException::withMessages(['chargeable_days' => 'The supplied chargeable days do not match the employee schedule calculation.']);
            }
        } catch (ValidationException $exception) {
            $scheduleErrors = $exception->errors()['schedule'] ?? $exception->errors()['fromDate'] ?? [];
            $firstScheduleError = (string) collect($scheduleErrors)->flatten()->first();
            if (! array_key_exists('schedule', $exception->errors())
                && ! str_starts_with($firstScheduleError, 'No Work Schedule covers ')
                && ! str_starts_with($firstScheduleError, 'The effective Work Schedule has no pattern for ')) {
                throw $exception;
            }
            if (blank($data['chargeable_days'] ?? null) || blank($data['manual_override_reason'] ?? null)) {
                throw ValidationException::withMessages(['chargeable_days' => 'No schedule calculation is available. Enter a verified day count and mandatory override reason.']);
            }
            $fromCycle = $this->cycles->forDate($employee, $from);
            $toCycle = $this->cycles->forDate($employee, $to);
            if (! $fromCycle->start->equalTo($toCycle->start)) {
                throw ValidationException::withMessages(['chargeable_days' => 'Manual day overrides cannot span two anniversary periods. Split the historical entry by leave period.']);
            }
            $manual = true;
            $chargeable = number_format((float) $data['chargeable_days'], 2, '.', '');
            $days = [];
        }

        $segments = $manual
            ? [[
                'period_start' => $fromCycle->start->toDateString(), 'period_end' => $fromCycle->end->toDateString(),
                'start' => $from, 'end' => $to, 'days' => $chargeable, 'daily_rows' => [],
            ]]
            : $this->groupByAnniversary($employee, $days);

        $workingDates = collect($days)->where('counts_as_leave', true)->pluck('leave_date');
        $conflictDates = $workingDates->values();
        if ($conflictDates->isEmpty()) {
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                $conflictDates->push($date->toDateString());
            }
        }
        $attendanceConflicts = EmployeeAttendance::query()->where('employee_id', $employee->id)
            ->where('status', '!=', AttendanceStatus::Absent->value);
        if ($conflictDates->isNotEmpty()) {
            $attendanceConflicts->where(function ($query) use ($conflictDates): void {
                foreach ($conflictDates as $date) {
                    $query->orWhereDate('attendance_date', $date);
                }
            });
        }
        if ($conflictDates->isNotEmpty() && $attendanceConflicts->exists()) {
            throw ValidationException::withMessages(['from_date' => 'Existing Present, Late, or Half-Day attendance conflicts with one or more selected dates. Original attendance cannot be overwritten.']);
        }
        if ($conflictDates->isNotEmpty() && CompensatoryOff::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved', 'used'])->whereIn('off_date', $conflictDates)->exists()) {
            throw ValidationException::withMessages(['from_date' => 'The selected dates conflict with a pending or approved Comp Off record.']);
        }

        $warnings = [];
        foreach ($segments as $segment) {
            if (! $type->consumes_annual_entitlement || in_array($data['approval_status'], ['rejected', 'cancelled'], true)) {
                continue;
            }
            $balance = $this->balances->forDate($employee, $segment['start']);
            if (bccomp($segment['days'], $balance->availableAfterPending, 2) > 0) {
                if ($data['approval_status'] !== 'approved') {
                    throw ValidationException::withMessages(['chargeable_days' => 'The pending historical entry exceeds the available annual entitlement in this leave period.']);
                }
                $warnings[] = 'The verified historical approval exceeds the available balance for '.$segment['period_start'].' – '.$segment['period_end'].'.';
            }
        }
        if ($data['approval_status'] === 'approved'
            && (! $this->authorization->allows($actor, HrPermission::LeaveApprove) || ! $this->scope->canApproveEmployee($actor, $employee))) {
            throw new AuthorizationException;
        }

        return [
            'employee_id' => $employee->id, 'employee_code' => $employee->employee_id, 'employee_name' => $employee->name,
            'verified_joining_date' => $employee->joining_date->toDateString(), 'leave_type' => $type->name,
            'segments' => $segments,
            'chargeable_days' => collect($segments)->reduce(fn (string $sum, array $segment): string => bcadd($sum, (string) $segment['days'], 2), '0.00'),
            'calculation_method' => $manual ? 'manual_verified' : 'schedule',
            'manual_override_reason' => $manual ? trim($data['manual_override_reason']) : null,
            'warnings' => $warnings, 'attendance_change' => 'No attendance changes occur during preview.',
            'database_writes' => false,
        ];
    }

    /** @param array<int,array<string,mixed>> $days @return array<int,array<string,mixed>> */
    private function groupByAnniversary(Employee $employee, array $days): array
    {
        $segments = [];
        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day['leave_date']);
            $cycle = $this->cycles->forDate($employee, $date);
            $key = $cycle->start->toDateString();
            $segments[$key] ??= ['period_start' => $key, 'period_end' => $cycle->end->toDateString(), 'start' => $date, 'end' => $date, 'days' => '0.00', 'daily_rows' => []];
            $segments[$key]['start'] = $date->lt($segments[$key]['start']) ? $date : $segments[$key]['start'];
            $segments[$key]['end'] = $date->gt($segments[$key]['end']) ? $date : $segments[$key]['end'];
            $segments[$key]['daily_rows'][] = $day;
            if ($day['counts_as_leave']) {
                $segments[$key]['days'] = bcadd($segments[$key]['days'], (string) $day['leave_units'], 2);
            }
        }
        $segments = array_filter($segments, fn (array $segment): bool => bccomp($segment['days'], '0.00', 2) > 0);
        if ($segments === []) {
            throw ValidationException::withMessages(['from_date' => 'The date range has no chargeable working days.']);
        }

        return array_values($segments);
    }
}
