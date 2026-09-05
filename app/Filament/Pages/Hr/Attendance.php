<?php

namespace App\Filament\Pages\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\HrPermission;
use App\Models\EmployeeAttendance;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceQueryService;
use App\Services\Hr\HrScopeService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Attendance extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.hr.attendance';

    protected static ?string $slug = 'hr/attendance';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Attendance';

    #[Url]
    public string $period = 'this_month';

    #[Url]
    public string $fromDate = '';

    #[Url]
    public string $toDate = '';

    #[Url]
    public ?int $employeeId = null;

    #[Url]
    public ?int $teamId = null;

    public ?int $correctionAttendanceId = null;

    public string $correctionStatus = '';

    public string $correctionFirstCheckIn = '';

    public string $correctionLastCheckOut = '';

    public int $correctionLateMinutes = 0;

    public int $correctionEarlyCheckoutMinutes = 0;

    public string $correctionReason = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && collect([
            HrPermission::AttendanceViewOwn, HrPermission::AttendanceViewTeam, HrPermission::AttendanceViewAll,
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

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'fromDate', 'toDate', 'employeeId', 'teamId'], true)) {
            $this->resetPage();
        }
    }

    public function openCorrection(int $attendanceId): void
    {
        $attendance = app(HrScopeService::class)->attendanceQuery($this->user())->with(['employee', 'schedule'])->findOrFail($attendanceId);
        abort_unless(app(HrScopeService::class)->canCorrect($this->user(), $attendance->employee), 403);
        $this->correctionAttendanceId = $attendance->id;
        $this->correctionStatus = $attendance->status->value;
        $timezone = (string) ($attendance->schedule?->timezone ?: 'Asia/Karachi');
        $this->correctionFirstCheckIn = $attendance->first_check_in_at?->timezone($timezone)->format('Y-m-d\TH:i') ?? '';
        $this->correctionLastCheckOut = $attendance->last_check_out_at?->timezone($timezone)->format('Y-m-d\TH:i') ?? '';
        $this->correctionLateMinutes = (int) $attendance->late_minutes;
        $this->correctionEarlyCheckoutMinutes = (int) $attendance->early_departure_minutes;
        $this->correctionReason = '';
        $this->resetValidation();
        $this->dispatch('open-modal', id: 'correct-attendance');
    }

    public function saveCorrection(): void
    {
        $this->validate([
            'correctionStatus' => ['required'], 'correctionFirstCheckIn' => ['nullable', 'date'],
            'correctionLastCheckOut' => ['nullable', 'date', 'after_or_equal:correctionFirstCheckIn'],
            'correctionLateMinutes' => ['required', 'integer', 'min:0'], 'correctionReason' => ['required', 'string', 'max:2000'],
            'correctionEarlyCheckoutMinutes' => ['required', 'integer', 'min:0'],
        ]);
        try {
            $attendance = EmployeeAttendance::query()->findOrFail($this->correctionAttendanceId);
            app(AttendanceCorrectionService::class)->correct($attendance, [
                'status' => $this->correctionStatus,
                'first_check_in_at' => $this->correctionFirstCheckIn ?: null,
                'last_check_out_at' => $this->correctionLastCheckOut ?: null,
                'late_minutes' => $this->correctionLateMinutes,
                'early_departure_minutes' => $this->correctionEarlyCheckoutMinutes,
            ], $this->correctionReason, $this->user());
            $this->dispatch('close-modal', id: 'correct-attendance');
            Notification::make()->success()->title('Attendance corrected')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('correction'.ucfirst($field), $messages[0]);
            }
        }
    }

    public function getViewData(): array
    {
        [$from, $to] = $this->dateRange();
        $service = app(AttendanceQueryService::class);
        $query = $service->query($this->user(), $from, $to, $this->employeeId, $this->teamId);
        $employees = app(HrScopeService::class)->employeeQuery($this->user(), true)->orderBy('name')->get(['id', 'name', 'employee_id', 'team_id']);

        return [
            'rows' => (clone $query)->paginate(25),
            'summary' => $service->summary(clone $query),
            'employees' => $employees,
            'teams' => Team::query()->whereKey($employees->pluck('team_id')->filter()->unique())->orderBy('name')->pluck('name', 'id'),
            'showEmployee' => $employees->count() > 1,
            'canCorrect' => app(HrAuthorization::class)->allows($this->user(), HrPermission::AttendanceCorrect),
            'statuses' => collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()]),
            'rangeLabel' => $from->format('d M Y').' – '.$to->format('d M Y'),
        ];
    }

    private function dateRange(): array
    {
        $today = CarbonImmutable::today();

        return match ($this->period) {
            'today' => [$today, $today],
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'custom' => [CarbonImmutable::parse($this->fromDate ?: $today->startOfMonth()), CarbonImmutable::parse($this->toDate ?: $today)],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
