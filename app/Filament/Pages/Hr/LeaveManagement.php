<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkScheduleAssignment;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\HistoricalLeaveActivationService;
use App\Services\Hr\HrScopeService;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class LeaveManagement extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.hr.leave';

    protected static ?string $slug = 'hr/leave';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Leave';

    protected static ?string $title = 'Leave';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public ?int $employeeFilter = null;

    public ?int $leaveTypeId = null;

    public string $fromDate = '';

    public string $toDate = '';

    public string $reason = '';

    public string $recordEmployeeSearch = '';

    public ?int $recordEmployeeId = null;

    public ?int $recordLeaveTypeId = null;

    public string $recordFromDate = '';

    public string $recordToDate = '';

    public string $recordReason = '';

    public string $recordApprovalStatus = 'pending';

    public ?string $recordWorkingDays = null;

    public ?string $recordAvailableBalance = null;

    public ?string $recordPreviewError = null;

    public ?int $rejectingRequestId = null;

    public string $rejectionReason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['statusFilter', 'employeeFilter'], true)) {
            $this->resetPage();
        }

        if (in_array($property, ['recordLeaveTypeId', 'recordFromDate', 'recordToDate'], true)) {
            $this->refreshRecordPreview();
        }
    }

    public function updatedRecordEmployeeSearch(): void
    {
        $this->recordEmployeeId = null;
        $this->refreshRecordPreview();
    }

    public function openRecordLeave(): void
    {
        abort_unless(app(HrScopeService::class)->recordableEmployeeQuery($this->user())->exists(), 403);
        $this->resetRecordForm();
        $this->dispatch('open-modal', id: 'record-employee-leave');
    }

    public function selectRecordEmployee(int $employeeId): void
    {
        $employee = app(HrScopeService::class)->recordableEmployeeQuery($this->user())->whereKey($employeeId)->first();
        abort_unless($employee instanceof Employee, 403);
        $this->recordEmployeeId = $employee->id;
        $this->recordEmployeeSearch = "{$employee->employee_id} · {$employee->name}";
        $this->refreshRecordPreview();
    }

    public function clearRecordEmployee(): void
    {
        $this->recordEmployeeId = null;
        $this->recordEmployeeSearch = '';
        $this->refreshRecordPreview();
    }

    public function recordLeave(): void
    {
        try {
            app(LeaveService::class)->recordOnBehalf([
                'employee_id' => $this->recordEmployeeId,
                'leave_type_id' => $this->recordLeaveTypeId,
                'from_date' => $this->recordFromDate,
                'to_date' => $this->recordToDate,
                'reason' => $this->recordReason,
                'approval_status' => $this->recordApprovalStatus,
            ], $this->user());
            $approved = $this->recordApprovalStatus === 'approved';
            $this->resetRecordForm();
            $this->resetValidation();
            $this->dispatch('close-modal', id: 'record-employee-leave');
            Notification::make()->success()->title($approved ? 'Employee leave recorded and approved' : 'Employee leave recorded for approval')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $map = [
                'employee_id' => 'recordEmployeeId', 'leave_type_id' => 'recordLeaveTypeId',
                'from_date' => 'recordFromDate', 'fromDate' => 'recordFromDate',
                'to_date' => 'recordToDate', 'toDate' => 'recordToDate', 'reason' => 'recordReason',
                'approval_status' => 'recordApprovalStatus', 'joining_date' => 'recordFromDate',
            ];
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($map[$field] ?? 'recordReason', $messages[0]);
            }
        }
    }

    public function refreshRecordPreview(): void
    {
        $this->recordWorkingDays = null;
        $this->recordAvailableBalance = null;
        $this->recordPreviewError = null;

        if ($this->recordEmployeeId === null || $this->recordLeaveTypeId === null || $this->recordFromDate === '' || $this->recordToDate === '') {
            return;
        }

        try {
            $preview = app(LeaveService::class)->previewOnBehalf([
                'employee_id' => $this->recordEmployeeId,
                'leave_type_id' => $this->recordLeaveTypeId,
                'from_date' => $this->recordFromDate,
                'to_date' => $this->recordToDate,
            ], $this->user());
            $this->recordWorkingDays = $preview['working_days'];
            $this->recordAvailableBalance = $preview['annual_leave']
                ? $preview['available_after_pending']
                : 'Not applicable (Unpaid Leave)';
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->recordPreviewError = collect($exception->errors())->flatten()->first();
        }
    }

    public function recordableEmployees(): Collection
    {
        $query = app(HrScopeService::class)->recordableEmployeeQuery($this->user());
        $search = trim($this->recordEmployeeSearch);
        if ($search !== '') {
            $query->where(fn ($employees) => $employees
                ->where('name', 'like', "%{$search}%")
                ->orWhere('employee_id', 'like', "%{$search}%"));
        }

        return $query->orderBy('name')->limit(15)->get(['id', 'employee_id', 'name']);
    }

    public function clearFilters(): void
    {
        $this->reset('statusFilter', 'employeeFilter');
        $this->resetPage();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && collect([
            HrPermission::LeaveViewOwn, HrPermission::LeaveViewTeam, HrPermission::LeaveViewAll,
        ])->contains(fn (HrPermission $permission): bool => app(HrAuthorization::class)->allows($user, $permission));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function submitLeave(): void
    {
        try {
            app(LeaveService::class)->submit([
                'leave_type_id' => $this->leaveTypeId, 'from_date' => $this->fromDate,
                'to_date' => $this->toDate, 'reason' => $this->reason,
            ], $this->user());
            $this->reset('leaveTypeId', 'fromDate', 'toDate', 'reason');
            $this->resetValidation();
            Notification::make()->success()->title('Leave request submitted')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $map = ['leave_type_id' => 'leaveTypeId', 'from_date' => 'fromDate', 'to_date' => 'toDate', 'joining_date' => 'fromDate'];
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($map[$field] ?? $field, $messages[0]);
            }
        }
    }

    public function approve(int $requestId): void
    {
        try {
            app(LeaveService::class)->approve(LeaveRequest::query()->with('employee')->findOrFail($requestId), $this->user());
            Notification::make()->success()->title('Leave approved')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Cannot approve Leave')->body(collect($exception->errors())->flatten()->first())->send();
        }
    }

    public function openReject(int $requestId): void
    {
        $request = LeaveRequest::query()->with('employee')->findOrFail($requestId);
        abort_unless(app(HrScopeService::class)->canApprove($this->user(), $request), 403);
        $this->rejectingRequestId = $request->id;
        $this->rejectionReason = '';
        $this->resetValidation('rejectionReason');
        $this->dispatch('open-modal', id: 'reject-leave');
    }

    public function reject(): void
    {
        $this->validate(['rejectionReason' => ['required', 'string', 'max:2000']]);
        app(LeaveService::class)->reject(LeaveRequest::query()->with('employee')->findOrFail($this->rejectingRequestId), $this->rejectionReason, $this->user());
        $this->dispatch('close-modal', id: 'reject-leave');
        Notification::make()->success()->title('Leave rejected')->send();
    }

    public function cancel(int $requestId): void
    {
        try {
            app(LeaveService::class)->cancel(LeaveRequest::query()->findOrFail($requestId), $this->user());
            Notification::make()->success()->title('Leave request cancelled')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Cannot cancel Leave')->body(collect($exception->errors())->flatten()->first())->send();
        }
    }

    public function getViewData(): array
    {
        $user = $this->user();
        $scope = app(HrScopeService::class);
        $employees = $scope->employeeQuery($user, false)->orderBy('name')->get(['id', 'employee_id', 'name']);
        $showEmployee = $employees->count() > 1;
        $query = $scope->leaveQuery($user)
            ->with(['employee:id,employee_id,name,team_id,user_id', 'type:id,name,code,consumes_annual_entitlement', 'recordedBy:id,name', 'decidedBy:id,name'])
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($showEmployee && $this->employeeFilter !== null, fn ($query) => $query->where('employee_id', $this->employeeFilter))
            ->latest('submitted_at');
        $balance = null;
        $balanceError = null;
        try {
            $balance = app(LeaveBalanceService::class)->currentFor($user->employee);
        } catch (ValidationException $exception) {
            $balanceError = collect($exception->errors())->flatten()->first();
        }
        $balancePolicy = $balance?->policyId === null ? null : LeavePolicy::query()->find($balance->policyId);
        $balancePeriod = $balance?->periodStart !== null && $balance->periodEnd !== null
            ? CarbonImmutable::parse($balance->periodStart)->format('d M Y').' – '.CarbonImmutable::parse($balance->periodEnd)->format('d M Y')
            : null;
        $recordEmployee = $this->recordEmployeeId === null
            ? null
            : $scope->recordableEmployeeQuery($user)->whereKey($this->recordEmployeeId)->first();

        return [
            'rows' => $query->paginate(20),
            'balance' => $balance,
            'balancePolicy' => $balancePolicy,
            'balanceError' => $balanceError,
            'balancePeriod' => $balancePeriod,
            'annualWorkflowActive' => app(HistoricalLeaveActivationService::class)->isActive()
                && app(HistoricalLeaveActivationService::class)->isReviewed($user->employee),
            'employees' => $employees,
            'leaveTypes' => LeaveType::query()->where('status', true)->whereIn('code', ['annual', 'unpaid'])->orderBy('name')->get(),
            'canRequest' => app(HrAuthorization::class)->allows($user, HrPermission::LeaveRequest),
            'canApprove' => app(HrAuthorization::class)->allows($user, HrPermission::LeaveApprove),
            'canRecordOnBehalf' => $scope->recordableEmployeeQuery($user)->exists(),
            'recordableEmployees' => $this->recordableEmployees(),
            'canRecordApproval' => $recordEmployee instanceof Employee && $scope->canApproveEmployee($user, $recordEmployee),
            'showEmployee' => $showEmployee,
            'scheduleConfigured' => WorkScheduleAssignment::query()
                ->where(fn ($query) => $query->where('employee_id', $user->employee->id)
                    ->orWhere(fn ($team) => $team->whereNull('employee_id')->where('team_id', $user->employee->team_id)))
                ->whereHas('schedule.days')->exists(),
        ];
    }

    public function canApproveRequest(LeaveRequest $request): bool
    {
        return app(HrScopeService::class)->canApprove($this->user(), $request);
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }

    private function resetRecordForm(): void
    {
        $this->reset(
            'recordEmployeeSearch', 'recordEmployeeId', 'recordLeaveTypeId', 'recordFromDate', 'recordToDate',
            'recordReason', 'recordWorkingDays', 'recordAvailableBalance', 'recordPreviewError',
        );
        $this->recordApprovalStatus = 'pending';
    }
}
