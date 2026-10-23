<?php

namespace App\Filament\Pages\Hr;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeeJoiningDateAudit;
use App\Models\HistoricalLeaveRecord;
use App\Models\LeaveCycleAdjustment;
use App\Models\LeaveEntitlementAdjustment;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\HistoricalLeaveActivationService;
use App\Services\Hr\HistoricalLeaveAuthorization;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmployeeLeaveDashboard extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.hr.employee-leave-dashboard';

    protected static ?string $slug = 'hr/employee-leave-dashboard';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Employee Leave Dashboard';

    protected static ?string $title = 'Employee Leave Dashboard';

    #[Url]
    public string $employeeSearch = '';

    #[Url]
    public string $employmentFilter = 'all';

    #[Url]
    public string $verificationFilter = 'all';

    #[Url]
    public ?int $selectedEmployeeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $authorization = app(HrAuthorization::class);

        return $authorization->allows($user, HrPermission::LeaveHistoricalView)
            && ($authorization->allows($user, HrPermission::LeaveViewAll)
                || $authorization->allows($user, HrPermission::LeaveViewTeam));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selectedEmployee();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selectedEmployee();
    }

    public function updatedEmployeeSearch(): void
    {
        $this->resetPage('employeesPage');
    }

    public function updatedEmploymentFilter(): void
    {
        $this->resetPage('employeesPage');
    }

    public function updatedVerificationFilter(): void
    {
        $this->resetPage('employeesPage');
    }

    public function selectEmployee(int $employeeId): void
    {
        abort_unless($this->visibleEmployees()->whereKey($employeeId)->exists(), 403);
        $this->selectedEmployeeId = $employeeId;
        foreach (['historicalPage', 'requestsPage', 'adjustmentsPage', 'joiningAuditPage'] as $page) {
            $this->resetPage($page);
        }
    }

    public function canActivateAnnualLeave(): bool
    {
        $user = $this->user();

        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && app(HrAuthorization::class)->allows($user, HrPermission::LeaveManagePolicy);
    }

    public function activateSelectedEmployee(): void
    {
        abort_unless($this->canActivateAnnualLeave(), 403);
        $employee = $this->selectedEmployee();
        abort_unless($employee instanceof Employee, 403);
        app(HistoricalLeaveActivationService::class)->activateEmployees([$employee->id], $this->user());
        Notification::make()->success()->title('Annual Leave enabled for this employee')->send();
    }

    public function getViewData(): array
    {
        $employees = $this->visibleEmployees()
            ->when(trim($this->employeeSearch) !== '', function (Builder $query): void {
                $search = trim($this->employeeSearch);
                $query->where(fn (Builder $employees) => $employees
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%"));
            })
            ->when($this->employmentFilter === 'active', fn (Builder $query) => $query->where('status', true))
            ->when($this->employmentFilter === 'inactive', fn (Builder $query) => $query->where('status', false))
            ->when($this->verificationFilter === 'verified', fn (Builder $query) => $query->whereNotNull('joining_date')->whereNotNull('joining_date_verified_at'))
            ->when($this->verificationFilter === 'unverified', fn (Builder $query) => $query->where(fn (Builder $employees) => $employees->whereNull('joining_date')->orWhereNull('joining_date_verified_at')))
            ->orderBy('name')->orderBy('id')->paginate(12, ['*'], 'employeesPage');

        $employee = $this->selectedEmployee();
        $balance = null;
        $cycle = null;
        $balanceError = null;
        $reviewed = false;
        $activated = false;
        if ($employee !== null) {
            $activation = app(HistoricalLeaveActivationService::class);
            $reviewed = $activation->isReviewed($employee);
            $activated = $activation->isActiveFor($employee);
            try {
                $balance = app(LeaveBalanceService::class)->currentFor($employee);
                $cycle = app(AnnualLeaveCycleResolver::class)->forDate($employee, CarbonImmutable::today());
            } catch (ValidationException $exception) {
                $balanceError = (string) collect($exception->errors())->flatten()->first();
            }
        }

        return [
            'employees' => $employees,
            'employee' => $employee,
            'balance' => $balance,
            'cycle' => $cycle,
            'balanceError' => $balanceError,
            'reviewed' => $reviewed,
            'activated' => $activated,
            'activationStatus' => $employee === null ? null : app(HistoricalLeaveActivationService::class)->employeeStatus($employee),
            'latestReview' => $employee === null ? null : DB::table('historical_leave_reconciliation_reviews')
                ->where('employee_id', $employee->id)->orderByDesc('id')->first(['reviewed_at']),
            'historicalRecords' => $employee === null ? null : HistoricalLeaveRecord::query()
                ->where('employee_id', $employee->id)->with(['type', 'recorder', 'events.actor'])
                ->orderByDesc('from_date')->orderByDesc('id')->paginate(10, ['*'], 'historicalPage'),
            'leaveRequests' => $employee === null ? null : LeaveRequest::query()
                ->where('employee_id', $employee->id)->with(['type', 'events.actor'])
                ->orderByDesc('from_date')->orderByDesc('id')->paginate(10, ['*'], 'requestsPage'),
            'cycleAdjustments' => $employee === null ? null : LeaveCycleAdjustment::query()
                ->where('employee_id', $employee->id)->with('recorder')->orderByDesc('recorded_at')
                ->paginate(10, ['*'], 'adjustmentsPage'),
            'legacyAdjustments' => $employee === null ? collect() : LeaveEntitlementAdjustment::query()
                ->where('employee_id', $employee->id)->orderByDesc('leave_year')->get(),
            'joiningAudits' => $employee === null ? null : EmployeeJoiningDateAudit::query()
                ->where('employee_id', $employee->id)->with('actor')->orderByDesc('occurred_at')
                ->paginate(10, ['*'], 'joiningAuditPage'),
        ];
    }

    public function downloadEmployeeExcel(int $employeeId): mixed
    {
        abort_unless(static::canAccess(), 403);
        $employee = $this->visibleEmployees()->whereKey($employeeId)->first();
        abort_unless($employee instanceof Employee, 403);
        $balance = null;
        try {
            $balance = app(LeaveBalanceService::class)->currentFor($employee);
        } catch (ValidationException) {
            // An unverified joining date has no trustworthy anniversary balance.
        }
        $activation = app(HistoricalLeaveActivationService::class);
        $reviewed = $activation->isReviewed($employee);
        $status = $activation->employeeStatus($employee);

        return response()->streamDownload(function () use ($employee, $balance, $status): void {
            $workbook = new Spreadsheet;
            $this->writeSheet($workbook, 'Balance', [
                ['Employee', $employee->name], ['Employee Code', $employee->employee_id],
                ['Employment', $employee->status ? 'Active' : 'Inactive'],
                ['Verified Joining Date', $employee->joining_date_verified_at !== null ? $employee->joining_date?->toDateString() : 'Not verified'],
                ['Status', $status], ['Leave Year Start', $balance?->periodStart ?? 'Not available'],
                ['Leave Year End', $balance?->periodEnd ?? 'Not available'],
                ['Next Anniversary', $balance?->periodEnd === null ? 'Not available' : CarbonImmutable::parse($balance->periodEnd)->addDay()->toDateString()],
                ['Annual Entitlement', $balance?->entitlement ?? 'Not available'],
                ['Balance Adjustments', $balance?->adjustments ?? 'Not available'],
                ['Carry Forward', $balance?->carryForward ?? 'Not available'],
                ['Previous Leave Used', $balance?->historicalUsed ?? 'Not available'],
                ['ERP Leave Used', $balance?->currentUsed ?? 'Not available'],
                ['Approved Leave Used', $balance?->used ?? 'Not available'],
                ['Pending Leave', $balance?->pending ?? 'Not available'],
                ['Remaining Before Pending', $balance?->remaining ?? 'Not available'],
                ['Available After Pending', $balance?->availableAfterPending ?? 'Not available'],
            ], first: true);
            $historical = HistoricalLeaveRecord::query()->where('employee_id', $employee->id)->with(['type', 'events.actor'])->orderBy('id')->get();
            $this->writeSheet($workbook, 'Previous Leave', array_merge(
                [['Reference', 'Type', 'Start', 'End', 'Days', 'Status', 'Reason', 'Notes', 'Correction Of']],
                $historical->map(fn ($record) => [
                    $record->reference, $record->type?->name, $record->from_date?->toDateString(),
                    $record->to_date?->toDateString(), $record->chargeable_days, $record->approval_status,
                    $record->reason, $record->remarks, $record->correction_of_record_id,
                ])->all(),
            ));
            $requests = LeaveRequest::query()->where('employee_id', $employee->id)->with(['type', 'events.actor'])->orderBy('id')->get();
            $this->writeSheet($workbook, 'ERP Leave', array_merge(
                [['Reference', 'Type', 'Start', 'End', 'Requested Days', 'Status', 'Reason']],
                $requests->map(fn ($request) => [
                    $request->reference, $request->type?->name, $request->from_date?->toDateString(),
                    $request->to_date?->toDateString(), $request->requested_working_days, $request->status, $request->reason,
                ])->all(),
            ));
            $adjustments = LeaveCycleAdjustment::query()->where('employee_id', $employee->id)->orderBy('id')->get();
            $this->writeSheet($workbook, 'Adjustments', array_merge(
                [['Reference', 'Leave Year Start', 'Type', 'Days', 'Reason', 'Recorded At']],
                $adjustments->map(fn ($adjustment) => [
                    $adjustment->reference, $adjustment->leave_period_start?->toDateString(),
                    $adjustment->adjustment_type, $adjustment->days, $adjustment->reason,
                    $adjustment->recorded_at?->toDateTimeString(),
                ])->all(),
                LeaveEntitlementAdjustment::query()->where('employee_id', $employee->id)->orderBy('id')->get()
                    ->map(fn ($adjustment) => [$adjustment->reference, $adjustment->leave_year, 'Legacy', $adjustment->days, $adjustment->reason, $adjustment->created_at?->toDateTimeString()])->all(),
            ));
            $audits = EmployeeJoiningDateAudit::query()->where('employee_id', $employee->id)->with('actor')->orderBy('id')->get();
            $this->writeSheet($workbook, 'Joining Date Audit', array_merge(
                [['Previous Date', 'Verified Date', 'Action', 'Reason', 'Changed By', 'Changed At']],
                $audits->map(fn ($audit) => [
                    $audit->previous_joining_date?->toDateString(), $audit->corrected_joining_date?->toDateString(),
                    $audit->action, $audit->reason, $audit->actor?->name, $audit->occurred_at?->toDateTimeString(),
                ])->all(),
            ));
            $auditRows = [['Source', 'Reference', 'Action', 'Changed By', 'Changed At', 'Reason']];
            foreach ($historical as $record) {
                foreach ($record->events as $event) {
                    $auditRows[] = ['Previous Leave', $record->reference, $event->event_type,
                        $event->actor?->name, $event->occurred_at?->toDateTimeString(), $event->reason];
                }
            }
            foreach ($requests as $request) {
                foreach ($request->events as $event) {
                    $auditRows[] = ['ERP Leave', $request->reference, $event->event_type,
                        $event->actor?->name, $event->occurred_at, $event->reason];
                }
            }
            $this->writeSheet($workbook, 'Leave Audit', $auditRows);
            (new Xlsx($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, 'employee_leave_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $employee->employee_id).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function visibleEmployees(): Builder
    {
        return app(HistoricalLeaveAuthorization::class)->employeeQuery(
            $this->user(), HrPermission::LeaveHistoricalView, requireVerifiedJoiningDate: false,
        );
    }

    private function selectedEmployee(): ?Employee
    {
        if ($this->selectedEmployeeId === null) {
            return null;
        }

        $employee = $this->visibleEmployees()->whereKey($this->selectedEmployeeId)->first();
        abort_unless($employee instanceof Employee, 403);

        return $employee;
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function writeSheet(Spreadsheet $workbook, string $name, array $rows, bool $first = false): void
    {
        $sheet = $first ? $workbook->getActiveSheet() : $workbook->createSheet();
        $sheet->setTitle($name);
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1), (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
    }
}
