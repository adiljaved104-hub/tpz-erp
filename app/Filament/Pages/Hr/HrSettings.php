<?php

namespace App\Filament\Pages\Hr;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\AttendancePolicy;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\HrPolicyService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class HrSettings extends Page
{
    protected string $view = 'filament.pages.hr.settings';

    protected static ?string $slug = 'hr/settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'HR Settings';

    public string $effectiveFrom = '';

    public string $officeStartTime = '';

    public string $officeEndTime = '';

    public int $graceMinutes = 0;

    public int $lateOccurrencesForPenalty = 3;

    public string $absenceEquivalentPenaltyDays = '1.00';

    public ?int $halfDayMinimumMinutes = null;

    public string $annualLeaveEntitlementDays = '12.00';

    public bool $managerApprovalEnabled = false;

    public bool $halfDayLeaveEnabled = false;

    public bool $compensatoryOffRequiresApproval = true;

    public ?int $compensatoryOffExpiryDays = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && app(HrAuthorization::class)->allows($user, HrPermission::AttendanceManagePolicy)
            && app(HrAuthorization::class)->allows($user, HrPermission::LeaveManagePolicy);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $attendance = AttendancePolicy::query()->where('status', true)->whereNull('effective_to')->latest('effective_from')->firstOrFail();
        $leave = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->latest('effective_from')->firstOrFail();
        $this->officeStartTime = substr($attendance->office_start_time, 0, 5);
        $this->officeEndTime = substr($attendance->office_end_time, 0, 5);
        $this->graceMinutes = $attendance->grace_minutes;
        $this->lateOccurrencesForPenalty = $attendance->late_occurrences_for_penalty;
        $this->absenceEquivalentPenaltyDays = (string) $attendance->absence_equivalent_penalty_days;
        $this->halfDayMinimumMinutes = $attendance->half_day_minimum_minutes;
        $this->annualLeaveEntitlementDays = (string) $leave->annual_leave_entitlement_days;
        $this->managerApprovalEnabled = $leave->manager_approval_enabled;
        $this->halfDayLeaveEnabled = $leave->half_day_leave_enabled;
        $this->compensatoryOffRequiresApproval = $leave->compensatory_off_requires_approval;
        $this->compensatoryOffExpiryDays = $leave->compensatory_off_expiry_days;
    }

    public function savePolicy(): void
    {
        try {
            app(HrPolicyService::class)->createVersion([
                'effective_from' => $this->effectiveFrom, 'office_start_time' => $this->officeStartTime,
                'office_end_time' => $this->officeEndTime, 'grace_minutes' => $this->graceMinutes,
                'late_occurrences_for_penalty' => $this->lateOccurrencesForPenalty,
                'absence_equivalent_penalty_days' => $this->absenceEquivalentPenaltyDays,
                'half_day_minimum_minutes' => $this->halfDayMinimumMinutes,
                'annual_leave_entitlement_days' => $this->annualLeaveEntitlementDays,
                'manager_approval_enabled' => $this->managerApprovalEnabled,
                'half_day_leave_enabled' => $this->halfDayLeaveEnabled,
                'compensatory_off_requires_approval' => $this->compensatoryOffRequiresApproval,
                'compensatory_off_expiry_days' => $this->compensatoryOffExpiryDays,
            ], auth()->user());
            Notification::make()->success()->title('Future HR policy saved')->body('Historical policies were preserved.')->send();
            $this->redirect(static::getUrl(), navigate: true);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $map = collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [str($key)->camel()->toString() => $messages]);
            foreach ($map as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function getViewData(): array
    {
        return [
            'attendanceHistory' => AttendancePolicy::query()->latest('effective_from')->get(),
            'leaveHistory' => LeavePolicy::query()->latest('effective_from')->get(),
            'schedule' => WorkSchedule::query()->where('code', 'PK_OFFICE')->first(),
        ];
    }
}
