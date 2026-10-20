<?php

namespace App\Filament\Pages\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\HrPermission;
use App\Models\Employee;
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

    protected static ?int $navigationSort = 1;

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

    #[Url]
    public string $employeeSearch = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $dateFilter = '';

    public string $calendarMonth = '';

    public ?int $correctionAttendanceId = null;

    public string $correctionStatus = '';

    public string $correctionFirstCheckIn = '';

    public string $correctionLastCheckOut = '';

    public int $correctionLateMinutes = 0;

    public int $correctionEarlyCheckoutMinutes = 0;

    public string $correctionReason = '';

    public ?int $manualEmployeeId = null;

    public string $manualAttendanceDate = '';

    public string $manualStatus = 'present';

    public string $manualFirstCheckIn = '';

    public string $manualLastCheckOut = '';

    public string $manualReason = '';

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
        $this->calendarMonth = now('Asia/Karachi')->format('Y-m');
        $this->manualAttendanceDate = now('Asia/Karachi')->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'fromDate', 'toDate', 'employeeId', 'teamId', 'employeeSearch', 'statusFilter', 'dateFilter'], true)) {
            $this->resetPage();
        }
    }

    public function previousCalendarMonth(): void
    {
        $this->shiftCalendarMonth(-1);
    }

    public function nextCalendarMonth(): void
    {
        $this->shiftCalendarMonth(1);
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

    public function openManualAttendance(): void
    {
        abort_unless(app(AttendanceCorrectionService::class)->canCreateManual($this->user()), 403);
        $this->reset('manualEmployeeId', 'manualFirstCheckIn', 'manualLastCheckOut', 'manualReason');
        $this->manualAttendanceDate = now('Asia/Karachi')->toDateString();
        $this->manualStatus = AttendanceStatus::Present->value;
        $this->resetValidation();
        $this->dispatch('open-modal', id: 'create-manual-attendance');
    }

    public function saveManualAttendance(): void
    {
        abort_unless(app(AttendanceCorrectionService::class)->canCreateManual($this->user()), 403);

        $this->validate([
            'manualEmployeeId' => ['required', 'integer'],
            'manualAttendanceDate' => ['required', 'date'],
            'manualStatus' => ['required'],
            'manualFirstCheckIn' => ['nullable', 'date_format:H:i'],
            'manualLastCheckOut' => ['nullable', 'date_format:H:i'],
            'manualReason' => ['required', 'string', 'max:2000'],
        ]);

        $date = $this->manualAttendanceDate;
        try {
            app(AttendanceCorrectionService::class)->createManual([
                'employee_id' => $this->manualEmployeeId,
                'attendance_date' => $date,
                'status' => $this->manualStatus,
                'first_check_in_at' => $this->manualFirstCheckIn !== '' ? $date.' '.$this->manualFirstCheckIn : null,
                'last_check_out_at' => $this->manualLastCheckOut !== '' ? $date.' '.$this->manualLastCheckOut : null,
                'reason' => $this->manualReason,
            ], $this->user());
            $this->dispatch('close-modal', id: 'create-manual-attendance');
            $this->reset('manualEmployeeId', 'manualFirstCheckIn', 'manualLastCheckOut', 'manualReason');
            $this->manualAttendanceDate = now('Asia/Karachi')->toDateString();
            Notification::make()->success()->title('Manual Attendance recorded')->body('The entry is audited and protected from biometric overwrite.')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('manual'.str($field)->studly()->toString(), $messages[0]);
            }
            Notification::make()->danger()->title('Manual Attendance could not be recorded')->body(collect($exception->errors())->flatten()->first())->send();
        }
    }

    public function getViewData(): array
    {
        [$from, $to] = $this->dateRange();
        $service = app(AttendanceQueryService::class);
        $user = $this->user();
        $scope = app(HrScopeService::class);
        $queryFrom = $this->dateFilter !== '' ? CarbonImmutable::parse($this->dateFilter, 'Asia/Karachi') : $from;
        $queryTo = $this->dateFilter !== '' ? $queryFrom : $to;
        $query = $service->query($user, $queryFrom, $queryTo, $this->employeeId, $this->teamId)
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter));
        $scopedEmployees = $scope->employeeQuery($user, true)->orderBy('name')->get(['id', 'name', 'employee_id', 'team_id']);
        $search = trim($this->employeeSearch);
        $employees = $scopedEmployees->filter(fn (Employee $employee): bool => $search === ''
            || str($employee->name.' '.$employee->employee_id)->lower()->contains(str($search)->lower())
            || (int) $employee->id === (int) $this->employeeId)->values();
        $today = CarbonImmutable::today('Asia/Karachi');
        $todayQuery = $service->query($user, $today, $today);

        try {
            $month = $this->calendarMonth === ''
                ? $today->startOfMonth()
                : CarbonImmutable::createFromFormat('!Y-m', $this->calendarMonth, 'Asia/Karachi')->startOfMonth();
        } catch (\Throwable) {
            $month = $today->startOfMonth();
        }
        $calendarRecords = $service->query($user, $month, $month->endOfMonth(), $this->employeeId, $this->teamId)
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->get();
        $byDate = $calendarRecords->groupBy(fn (EmployeeAttendance $row): string => $row->attendance_date->toDateString());
        $calendarStart = $month->startOfWeek();
        $calendarDays = [];
        for ($dayIndex = 0; $dayIndex < 42; $dayIndex++) {
            $day = $calendarStart->addDays($dayIndex);
            $calendarDays[] = [
                'date' => $day,
                'in_month' => $day->month === $month->month,
                'records' => $byDate->get($day->toDateString(), collect())->take(4),
                'more_count' => max(0, $byDate->get($day->toDateString(), collect())->count() - 4),
            ];
        }

        return [
            'rows' => (clone $query)->with('corrections.actor:id,name')->paginate(25),
            'summary' => $service->summary(clone $todayQuery),
            'rangeSummary' => $service->summary(clone $query),
            'employees' => $employees,
            'teams' => Team::query()->whereKey($scopedEmployees->pluck('team_id')->filter()->unique())->orderBy('name')->pluck('name', 'id'),
            'showEmployee' => $scopedEmployees->count() > 1,
            'canCorrect' => app(HrAuthorization::class)->allows($this->user(), HrPermission::AttendanceCorrect),
            'canManual' => app(AttendanceCorrectionService::class)->canCreateManual($this->user()) && $scopedEmployees->isNotEmpty(),
            'statuses' => collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()]),
            'rangeLabel' => $from->format('d M Y').' – '.$to->format('d M Y'),
            'calendarDays' => $calendarDays,
            'calendarRecords' => $calendarRecords,
            'calendarMonthLabel' => $month->format('F Y'),
            'manualStatuses' => collect([AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Absent, AttendanceStatus::HalfDay])->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()]),
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

    private function shiftCalendarMonth(int $months): void
    {
        try {
            $month = $this->calendarMonth === ''
                ? CarbonImmutable::today('Asia/Karachi')->startOfMonth()
                : CarbonImmutable::createFromFormat('!Y-m', $this->calendarMonth, 'Asia/Karachi')->startOfMonth();
        } catch (\Throwable) {
            $month = CarbonImmutable::today('Asia/Karachi')->startOfMonth();
        }

        $this->calendarMonth = ($months < 0 ? $month->subMonthNoOverflow() : $month->addMonthNoOverflow())->format('Y-m');
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
