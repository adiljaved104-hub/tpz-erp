<?php

namespace App\Filament\Pages\Hr;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\CompensatoryOff;
use App\Models\CompOffApprovalEvent;
use App\Models\CompOffCreditRequest;
use App\Models\CompOffSundayDuty;
use App\Models\CompOffWalletEntry;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\CompensatoryOffService;
use App\Services\Hr\CompOffCreditService;
use App\Services\Hr\HrScopeService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompOff extends Page
{
    protected string $view = 'filament.pages.hr.comp-off';

    protected static ?string $slug = 'hr/comp-off';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Comp-Off';

    public string $fromDate = '';

    public string $toDate = '';

    public string $requestReason = '';

    public string $requestKey = '';

    public ?int $dutyEmployeeId = null;

    public string $dutyDate = '';

    public string $dutyStartTime = '11:00';

    public string $dutyEndTime = '17:00';

    public string $dutyReason = '';

    public ?int $editingDutyId = null;

    public string $editStartTime = '';

    public string $editEndTime = '';

    public string $editReason = '';

    public string $dutyKey = '';

    public string $decisionReason = '';

    public string $cancellationReason = '';

    public string $activeTab = 'overview';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && collect([
            HrPermission::LeaveViewOwn, HrPermission::LeaveViewTeam, HrPermission::LeaveViewAll,
            HrPermission::AttendanceViewTeam, HrPermission::AttendanceViewAll,
        ])->contains(fn (HrPermission $permission): bool => app(HrAuthorization::class)->allows($user, $permission));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->requestKey = (string) Str::uuid();
        $this->dutyKey = (string) Str::uuid();
    }

    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, ['overview', 'approvals', 'history'], true), 404);
        if ($tab === 'approvals') {
            abort_unless($this->canManageTeam(), 403);
        }

        $this->activeTab = $tab;
    }

    public function submitRequest(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            app(CompOffCreditService::class)->submit([
                'from_date' => $this->fromDate, 'to_date' => $this->toDate,
                'reason' => $this->requestReason, 'idempotency_key' => $this->requestKey,
            ], $user);
            $this->requestKey = (string) Str::uuid();
            $this->reset('fromDate', 'toDate', 'requestReason');
            Notification::make()->title('Comp-Off request sent for approval')->success()->send();
        } catch (ValidationException $exception) {
            $this->showError($exception);
        }
    }

    public function authorizeDuty(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            app(CompOffCreditService::class)->authorizeSunday([
                'employee_id' => $this->dutyEmployeeId, 'duty_date' => $this->dutyDate,
                'shift_start' => $this->dutyStartTime, 'shift_end' => $this->dutyEndTime,
                'reason' => $this->dutyReason, 'idempotency_key' => $this->dutyKey,
            ], $user);
            $this->dutyKey = (string) Str::uuid();
            $this->reset('dutyEmployeeId', 'dutyDate', 'dutyReason');
            $this->dutyStartTime = '11:00';
            $this->dutyEndTime = '17:00';
            Notification::make()->title('Sunday duty authorized')->success()->send();
        } catch (ValidationException $exception) {
            $this->showError($exception);
        }
    }

    public function startEditingDuty(int $id): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $duty = CompOffSundayDuty::query()->findOrFail($id);
        abort_unless(app(CompOffCreditService::class)->canEditSundayTimings($duty, $user), 403);
        $this->editingDutyId = $duty->id;
        $this->editStartTime = substr($duty->shift_start, 0, 5);
        $this->editEndTime = substr($duty->shift_end, 0, 5);
        $this->editReason = '';
    }

    public function cancelEditingDuty(): void
    {
        $this->reset('editingDutyId', 'editStartTime', 'editEndTime', 'editReason');
    }

    public function saveDutyTimings(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $this->editingDutyId !== null, 403);
        try {
            app(CompOffCreditService::class)->editSundayTimings(
                CompOffSundayDuty::query()->findOrFail($this->editingDutyId),
                ['shift_start' => $this->editStartTime, 'shift_end' => $this->editEndTime,
                    'reason' => $this->editReason], $user,
            );
            $this->cancelEditingDuty();
            Notification::make()->title('Sunday duty timings updated')->success()->send();
        } catch (ValidationException $exception) {
            $this->showError($exception);
        }
    }

    public function decideDuty(int $id, bool $approve): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            app(CompOffCreditService::class)->decideSunday(
                CompOffSundayDuty::query()->findOrFail($id), $approve, $user,
                $approve ? null : $this->decisionReason,
            );
            $this->decisionReason = '';
            Notification::make()->title($approve ? 'Credit added to wallet' : 'Sunday duty rejected')->success()->send();
        } catch (ValidationException $exception) {
            $this->showError($exception);
        }
    }

    public function decideRequest(int $id, bool $approve): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            app(CompOffCreditService::class)->decideRequest(
                CompOffCreditRequest::query()->findOrFail($id), $approve, $user,
                $approve ? null : $this->decisionReason,
            );
            $this->decisionReason = '';
            Notification::make()->title($approve ? 'Comp-Off approved' : 'Comp-Off rejected')->success()->send();
        } catch (ValidationException $exception) {
            $this->showError($exception);
        }
    }

    public function cancelRequest(int $id): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            app(CompOffCreditService::class)->cancel(
                CompOffCreditRequest::query()->findOrFail($id), $user, $this->cancellationReason,
            );
            $this->cancellationReason = '';
            Notification::make()->title('Comp-Off cancelled with audit history')->success()->send();
        } catch (ValidationException $exception) {
            $this->showError($exception);
        }
    }

    public function approveLegacy(int $id): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            app(CompensatoryOffService::class)->approve(CompensatoryOff::query()->findOrFail($id), $user);
            Notification::make()->title('Older Comp-Off record approved')->success()->send();
        } catch (ValidationException $exception) {
            $this->showError($exception);
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $scope = app(HrScopeService::class);
        $service = app(CompOffCreditService::class);
        $employee = $user->employee;
        $canManage = $this->canManageTeam();
        $companyWide = $canManage && in_array($employee->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
        $team = $canManage ? ($companyWide ? Employee::query() : $scope->employeeQuery($user, true))
            ->whereKeyNot($employee->id)->orderBy('name')
            ->get()->filter(fn (Employee $person): bool => $scope->canViewLeave($user, $person))->values() : collect();
        $teamIds = $team->pluck('id')->all();
        $walletRows = CompOffWalletEntry::query()->whereIn('employee_id', $teamIds)
            ->selectRaw('employee_id, SUM(earned_delta) AS earned, SUM(used_delta) AS used, SUM(reserved_delta) AS reserved')
            ->groupBy('employee_id')->get()->keyBy('employee_id');
        $teamWallets = $team->map(function (Employee $person) use ($walletRows): array {
            $row = $walletRows->get($person->id);
            $earned = (int) ($row?->earned ?? 0);
            $used = (int) ($row?->used ?? 0);
            $reserved = (int) ($row?->reserved ?? 0);

            return ['employee' => $person, 'earned' => $earned, 'used' => $used,
                'reserved' => $reserved, 'available' => $earned - $used - $reserved];
        });
        $mine = $employee ? CompOffCreditRequest::query()->with('days')->where('employee_id', $employee->id)->latest()->limit(20)->get() : collect();
        $duties = $canManage ? CompOffSundayDuty::query()->with('employee')->whereIn('employee_id', $teamIds)
            ->where('status', 'authorized')->oldest('duty_date')->limit(30)->get() : collect();
        $requests = $canManage ? CompOffCreditRequest::query()->with('employee', 'days')->whereIn('employee_id', $teamIds)
            ->where('status', 'pending')->oldest('submitted_at')->limit(30)->get() : collect();
        $historyIds = $employee ? array_values(array_unique(array_merge([$employee->id], $teamIds))) : [];

        return [
            'wallet' => $employee && $scope->canViewLeave($user, $employee) ? $service->balance($employee, $user) : null,
            'entries' => CompOffWalletEntry::query()->with(['employee', 'request', 'actor'])->whereIn('employee_id', $historyIds)
                ->latest('id')->limit(60)->get(),
            'myRequests' => $mine, 'pendingDuties' => $duties, 'pendingRequests' => $requests,
            'dutyEligibility' => $canManage && $this->activeTab === 'approvals'
                ? $duties->mapWithKeys(fn (CompOffSundayDuty $duty): array => [$duty->id => $service->sundayApprovalEligibility($duty)]) : collect(),
            'dutyTimingEditable' => $canManage && $this->activeTab === 'approvals'
                ? $duties->mapWithKeys(fn (CompOffSundayDuty $duty): array => [$duty->id => $service->canEditSundayTimings($duty, $user)]) : collect(),
            'teamEmployees' => $team->where('status', true), 'teamWallets' => $teamWallets, 'canManage' => $canManage,
            'legacyRecords' => $employee ? CompensatoryOff::query()->with('employee')
                ->whereIn('employee_id', $historyIds)
                ->latest()->limit(30)->get() : collect(),
            'legacyCanApprove' => $canManage ? app(HrAuthorization::class)->allows($user, HrPermission::LeaveApprove) : false,
            'legacyPending' => $canManage ? CompensatoryOff::query()->with('employee')->whereIn('employee_id', $teamIds)
                ->where('status', 'pending')->latest()->limit(30)->get() : collect(),
            'approvalEvents' => $employee ? CompOffApprovalEvent::query()->with(['actor', 'duty.employee', 'request.employee'])
                ->where(function ($query) use ($historyIds): void {
                    $query->whereHas('duty', fn ($duties) => $duties->whereIn('employee_id', $historyIds))
                        ->orWhereHas('request', fn ($requests) => $requests->whereIn('employee_id', $historyIds));
                })->latest('id')->limit(30)->get() : collect(),
        ];
    }

    private function canManageTeam(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->employee !== null
            && in_array($user->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin, EmployeeRole::Manager], true)
            && app(HrAuthorization::class)->allows($user, HrPermission::CompOffManage);
    }

    private function showError(ValidationException $exception): void
    {
        Notification::make()->title('Please check this Comp-Off request')
            ->body(collect($exception->errors())->flatten()->first() ?? 'The request could not be saved.')
            ->danger()->send();
    }
}
