<?php

namespace App\Services\Performance;

use App\Enums\AttendanceStatus;
use App\Enums\ComplaintStatus;
use App\Enums\SafetClaimStatus;
use App\Enums\TaskAssignmentStatus;
use App\Enums\TaskCompletionSubmissionStatus;
use App\Enums\WarrantyRepairStatus;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\PerformanceAuthorization;
use App\Services\ServiceCases\WarrantySlaService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PerformanceMatrixService
{
    public function __construct(
        private readonly PerformanceAuthorization $authorization,
        private readonly WarrantySlaService $warrantySla,
    ) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(string $period, ?string $customFrom = null, ?string $customTo = null): array
    {
        $today = CarbonImmutable::today();
        [$from, $to] = match ($period) {
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'last_30_days' => [$today->subDays(29), $today],
            'last_90_days' => [$today->subDays(89), $today],
            'custom' => [CarbonImmutable::parse($customFrom ?: $today->startOfMonth()), CarbonImmutable::parse($customTo ?: $today)],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };
        if ($to->isBefore($from)) {
            throw ValidationException::withMessages(['toDate' => 'The end date must be on or after the start date.']);
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function overview(User $actor, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $employees = $this->authorization->employeeQuery($actor)->with('team:id,name')->orderBy('name')->get(['id', 'user_id', 'name', 'employee_id', 'team_id', 'role']);
        $metrics = $this->metricsFor($employees, $from, $to);

        return $employees->map(fn (Employee $employee): array => [
            'employee' => $employee,
            'tasks' => $metrics['tasks'][$employee->id],
            'attendance' => $metrics['attendance'][$employee->id],
            'operations' => $metrics['operations'][$employee->id],
        ]);
    }

    /** @return array<string, mixed> */
    public function employee(User $actor, Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        throw_unless($this->authorization->canViewEmployee($actor, $employee), AuthorizationException::class);
        $metrics = $this->metricsFor(new Collection([$employee->loadMissing('team:id,name')]), $from, $to);

        return [
            'employee' => $employee,
            'tasks' => $metrics['tasks'][$employee->id],
            'attendance' => $metrics['attendance'][$employee->id],
            'operations' => $metrics['operations'][$employee->id],
        ];
    }

    /** @return array{tasks: array<int, array<string, int>>, attendance: array<int, array<string, int|string|bool>>, operations: array<int, array<string, int>>} */
    private function metricsFor(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $userToEmployee = $employees->whereNotNull('user_id')->mapWithKeys(fn (Employee $employee): array => [(int) $employee->user_id => $employee->id]);

        return [
            'tasks' => $this->taskMetrics($ids, $from, $to),
            'attendance' => $this->attendanceMetrics($ids, $from, $to),
            'operations' => $this->operationalMetrics($ids, $userToEmployee, $from, $to),
        ];
    }

    /** @return array<int, array<string, int>> */
    private function taskMetrics(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $metrics = $this->defaults($employeeIds, $this->taskDefaults());
        if ($employeeIds === []) {
            return $metrics;
        }
        $assignments = TaskAssignment::query()->whereIn('employee_id', $employeeIds)->where('created_at', '<=', $to)
            ->where(function ($query) use ($from): void {
                $query->whereNotIn('status', [TaskAssignmentStatus::Cancelled->value, TaskAssignmentStatus::Removed->value])
                    ->orWhere('cancelled_at', '>=', $from)->orWhere('removed_at', '>=', $from);
            })
            ->with([
                'task:id,due_at',
                'completionSubmissions:id,task_assignment_id,status,submitted_at,decided_at',
            ])->get();
        foreach ($assignments as $assignment) {
            $item = &$metrics[$assignment->employee_id];
            if ($assignment->created_at?->betweenIncluded($from, $to)) {
                $item['assigned']++;
            }
            $pending = $assignment->completionSubmissions->contains('status', TaskCompletionSubmissionStatus::Pending);
            $current = $assignment->status;
            if ($current === TaskAssignmentStatus::Assigned) {
                $item['pending_assigned']++;
            } elseif ($current === TaskAssignmentStatus::InProgress && ! $pending) {
                $item['in_progress']++;
            } elseif ($current === TaskAssignmentStatus::Waiting && ! $pending) {
                $item['waiting']++;
            }
            if ($pending) {
                $item['awaiting_confirmation']++;
            }
            if ($current->isActive() && ! $pending && $assignment->task?->due_at?->isPast()) {
                $item['currently_overdue']++;
            }
            $returned = $assignment->completionSubmissions->where('status', TaskCompletionSubmissionStatus::Returned)
                ->contains(fn ($submission): bool => $submission->decided_at?->betweenIncluded($from, $to) === true);
            if ($returned) {
                $item['returned_for_rework']++;
            }
            if ($current !== TaskAssignmentStatus::Completed || $assignment->completed_at?->betweenIncluded($from, $to) !== true) {
                unset($item);

                continue;
            }
            $item['confirmed_completed']++;
            $confirmed = $assignment->completionSubmissions->where('status', TaskCompletionSubmissionStatus::Confirmed)
                ->sortByDesc(fn ($submission): string => ($submission->decided_at?->format('Y-m-d H:i:s.u') ?? '').':'.$submission->id)->first();
            if ($confirmed !== null && ($assignment->task?->due_at === null || $confirmed->submitted_at->lte($assignment->task->due_at))) {
                $item['completed_on_time']++;
            } else {
                $item['completed_late']++;
            }
            unset($item);
        }

        return $metrics;
    }

    /** @return array<int, array<string, int|string|bool>> */
    private function attendanceMetrics(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $metrics = $this->defaults($employeeIds, $this->attendanceDefaults());
        if ($employeeIds === []) {
            return $metrics;
        }
        $rows = DB::table('employee_attendances')->selectRaw('employee_id, status, COUNT(*) AS aggregate')
            ->whereIn('employee_id', $employeeIds)->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('employee_id', 'status')->get();
        $workingStatuses = [AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::Absent->value,
            AttendanceStatus::HalfDay->value, AttendanceStatus::ApprovedLeave->value, AttendanceStatus::UnpaidLeave->value];
        foreach ($rows as $row) {
            $count = (int) $row->aggregate;
            $item = &$metrics[(int) $row->employee_id];
            $item['has_data'] = true;
            if (in_array($row->status, $workingStatuses, true)) {
                $item['scheduled_working_days'] += $count;
            }
            $key = match ($row->status) {
                AttendanceStatus::Present->value => 'present', AttendanceStatus::Late->value => 'late',
                AttendanceStatus::Absent->value => 'actual_absent', AttendanceStatus::ApprovedLeave->value => 'approved_leave',
                AttendanceStatus::UnpaidLeave->value => 'unpaid_leave', AttendanceStatus::CompensatoryOff->value => 'comp_off',
                AttendanceStatus::PublicHoliday->value => 'public_holiday', AttendanceStatus::WeekendOff->value => 'scheduled_off',
                default => null,
            };
            if ($key !== null) {
                $item[$key] += $count;
            }
            unset($item);
        }
        $lateGroups = DB::table('employee_attendances')->selectRaw('employee_id, attendance_policy_id, substr(attendance_date, 1, 7) AS attendance_month, COUNT(*) AS aggregate')
            ->whereIn('employee_id', $employeeIds)->where('status', AttendanceStatus::Late->value)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('employee_id', 'attendance_policy_id', 'attendance_month')->get();
        $policies = AttendancePolicy::query()->whereKey($lateGroups->pluck('attendance_policy_id')->filter()->unique())->get()->keyBy('id');
        foreach ($lateGroups as $group) {
            $policy = $policies->get($group->attendance_policy_id);
            if ($policy === null) {
                continue;
            }
            $occurrences = intdiv((int) $group->aggregate, (int) $policy->late_occurrences_for_penalty);
            $penalty = bcmul((string) $occurrences, (string) $policy->absence_equivalent_penalty_days, 2);
            $metrics[(int) $group->employee_id]['late_penalty'] = bcadd($metrics[(int) $group->employee_id]['late_penalty'], $penalty, 2);
        }
        $earlyCheckout = DB::table('employee_attendances')
            ->selectRaw('employee_id, COUNT(*) AS occurrences, SUM(early_departure_minutes) AS minutes')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->where('early_departure_minutes', '>', 0)
            ->groupBy('employee_id')
            ->get();
        foreach ($earlyCheckout as $group) {
            $metrics[(int) $group->employee_id]['early_checkout_occurrences'] = (int) $group->occurrences;
            $metrics[(int) $group->employee_id]['early_checkout_minutes'] = (int) $group->minutes;
        }

        return $metrics;
    }

    /** @return array<int, array<string, int>> */
    private function operationalMetrics(array $employeeIds, Collection $userToEmployee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $metrics = $this->defaults($employeeIds, $this->operationDefaults());
        if ($employeeIds === []) {
            return $metrics;
        }
        $userIds = $userToEmployee->keys()->all();
        $claims = DB::table('safet_claims')->select(['assigned_to_user_id', 'status'])->whereIn('assigned_to_user_id', $userIds)
            ->whereBetween('created_at', [$from, $to])->get();
        foreach ($claims as $claim) {
            $item = &$metrics[$userToEmployee[(int) $claim->assigned_to_user_id]];
            $item['claims_handled']++;
            match ($claim->status) {
                SafetClaimStatus::NeedsFiling->value => $item['claims_needs_filing']++,
                SafetClaimStatus::Filed->value, SafetClaimStatus::InReview->value => $item['claims_filed_review']++,
                SafetClaimStatus::Approved->value => $item['claims_approved']++,
                SafetClaimStatus::Rejected->value, SafetClaimStatus::NotEligible->value => $item['claims_rejected_not_eligible']++,
                SafetClaimStatus::Paid->value => $item['claims_paid']++,
                default => null,
            };
            unset($item);
        }
        $warranties = WarrantyRepair::query()->whereIn('assigned_to_user_id', $userIds)->whereBetween('created_at', [$from, $to])
            ->get(['id', 'assigned_to_user_id', 'source', 'damaged_stock_event_id', 'status', 'received_at', 'completed_at']);
        foreach ($warranties as $case) {
            $item = &$metrics[$userToEmployee[(int) $case->assigned_to_user_id]];
            $item['warranty_handled']++;
            $closed = in_array($case->status, [WarrantyRepairStatus::Completed, WarrantyRepairStatus::Cancelled], true);
            $closed ? $item['warranty_completed']++ : $item['warranty_open']++;
            $sla = $this->warrantySla->status($case);
            if ($sla === 'Due Soon') {
                $item['warranty_due_soon']++;
            } elseif (in_array($sla, ['Overdue', 'SLA Breached'], true)) {
                $item['warranty_sla_breached']++;
            }
            if (in_array($case->status, [WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::WaitingForParts], true)) {
                $item['warranty_waiting_technician']++;
            }
            if ($case->isInternalCompanyOwnedRepair()) {
                $item['repairs_handled']++;
                $case->status === WarrantyRepairStatus::Completed ? $item['repairs_completed']++ : $item['repairs_open']++;
            }
            unset($item);
        }
        $complaints = DB::table('complaints')->select(['assigned_to_user_id', 'status'])->whereIn('assigned_to_user_id', $userIds)
            ->whereBetween('opened_at', [$from, $to])->get();
        foreach ($complaints as $case) {
            $item = &$metrics[$userToEmployee[(int) $case->assigned_to_user_id]];
            $item['complaints_handled']++;
            match ($case->status) {
                ComplaintStatus::Resolved->value, ComplaintStatus::Closed->value => $item['complaints_resolved']++,
                ComplaintStatus::Open->value, ComplaintStatus::InProgress->value => $item['complaints_open']++,
                ComplaintStatus::WaitingForCustomer->value, ComplaintStatus::WaitingForMarketplace->value,
                ComplaintStatus::WaitingForInternalTeam->value => $item['complaints_pending']++,
                default => null,
            };
            unset($item);
        }
        $returns = DB::table('customer_returns')->selectRaw('received_by_user_id, COUNT(*) AS aggregate')->whereIn('received_by_user_id', $userIds)
            ->whereBetween('received_at', [$from, $to])->groupBy('received_by_user_id')->get();
        foreach ($returns as $row) {
            $metrics[$userToEmployee[(int) $row->received_by_user_id]]['returns_received'] += (int) $row->aggregate;
        }
        $inspections = DB::table('customer_return_inspections')->selectRaw('inspected_by_user_id, COUNT(*) AS aggregate')->whereIn('inspected_by_user_id', $userIds)
            ->whereBetween('inspected_at', [$from, $to])->groupBy('inspected_by_user_id')->get();
        foreach ($inspections as $row) {
            $metrics[$userToEmployee[(int) $row->inspected_by_user_id]]['qc_completed'] += (int) $row->aggregate;
        }
        $orders = DB::table('orders')->selectRaw('handled_by_employee_id, COUNT(*) AS aggregate')->whereIn('handled_by_employee_id', $employeeIds)
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])->groupBy('handled_by_employee_id')->get();
        foreach ($orders as $row) {
            $metrics[(int) $row->handled_by_employee_id]['orders_handled'] += (int) $row->aggregate;
        }
        $fulfillments = DB::table('order_fulfillments')->selectRaw('fulfilled_by_user_id, COUNT(*) AS aggregate')->whereIn('fulfilled_by_user_id', $userIds)
            ->whereBetween('fulfilled_at', [$from, $to])->groupBy('fulfilled_by_user_id')->get();
        foreach ($fulfillments as $row) {
            $metrics[$userToEmployee[(int) $row->fulfilled_by_user_id]]['fulfillments_handled'] += (int) $row->aggregate;
        }
        foreach ($metrics as &$item) {
            $item['operational_cases'] = $item['claims_handled'] + $item['warranty_handled'] + $item['complaints_handled']
                + $item['returns_received'] + $item['orders_handled'];
        }

        return $metrics;
    }

    private function defaults(array $employeeIds, array $default): array
    {
        return collect($employeeIds)->mapWithKeys(fn (int $id): array => [$id => $default])->all();
    }

    private function taskDefaults(): array
    {
        return ['assigned' => 0, 'pending_assigned' => 0, 'in_progress' => 0, 'waiting' => 0, 'awaiting_confirmation' => 0,
            'confirmed_completed' => 0, 'completed_on_time' => 0, 'completed_late' => 0, 'currently_overdue' => 0, 'returned_for_rework' => 0];
    }

    private function attendanceDefaults(): array
    {
        return ['has_data' => false, 'scheduled_working_days' => 0, 'present' => 0, 'late' => 0, 'actual_absent' => 0,
            'approved_leave' => 0, 'unpaid_leave' => 0, 'comp_off' => 0, 'public_holiday' => 0, 'scheduled_off' => 0,
            'early_checkout_occurrences' => 0, 'early_checkout_minutes' => 0, 'late_penalty' => '0.00'];
    }

    private function operationDefaults(): array
    {
        return ['claims_handled' => 0, 'claims_needs_filing' => 0, 'claims_filed_review' => 0, 'claims_approved' => 0,
            'claims_rejected_not_eligible' => 0, 'claims_paid' => 0, 'warranty_handled' => 0, 'warranty_open' => 0,
            'warranty_completed' => 0, 'warranty_due_soon' => 0, 'warranty_sla_breached' => 0, 'warranty_waiting_technician' => 0,
            'complaints_handled' => 0, 'complaints_open' => 0, 'complaints_resolved' => 0, 'complaints_pending' => 0,
            'returns_received' => 0, 'qc_completed' => 0, 'repairs_handled' => 0, 'repairs_completed' => 0, 'repairs_open' => 0,
            'orders_handled' => 0, 'fulfillments_handled' => 0, 'operational_cases' => 0];
    }
}
