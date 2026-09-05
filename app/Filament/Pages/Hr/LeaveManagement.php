<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkScheduleAssignment;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\HrScopeService;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
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

    protected static ?string $navigationLabel = 'Leave';

    protected static ?string $title = 'Leave';

    #[Url]
    public string $statusFilter = '';

    public ?int $leaveTypeId = null;

    public string $fromDate = '';

    public string $toDate = '';

    public string $reason = '';

    public ?int $rejectingRequestId = null;

    public string $rejectionReason = '';

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
            $map = ['leave_type_id' => 'leaveTypeId', 'from_date' => 'fromDate', 'to_date' => 'toDate'];
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
        $query = app(HrScopeService::class)->leaveQuery($user)
            ->with(['employee:id,employee_id,name,team_id,user_id', 'type:id,name,code,consumes_annual_entitlement'])
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('submitted_at');
        $balance = app(LeaveBalanceService::class)->for($user->employee);

        return [
            'rows' => $query->paginate(20),
            'balance' => $balance,
            'leaveTypes' => LeaveType::query()->where('status', true)->whereIn('code', ['annual', 'unpaid'])->orderBy('name')->get(),
            'canRequest' => app(HrAuthorization::class)->allows($user, HrPermission::LeaveRequest),
            'canApprove' => app(HrAuthorization::class)->allows($user, HrPermission::LeaveApprove),
            'showEmployee' => app(HrScopeService::class)->employeeQuery($user, false)->count() > 1,
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
}
