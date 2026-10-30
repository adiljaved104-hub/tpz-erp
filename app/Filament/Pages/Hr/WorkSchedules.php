<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\WorkScheduleService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkSchedules extends Page
{
    protected string $view = 'filament.pages.hr.work-schedules';

    protected static ?string $slug = 'hr/work-schedules';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Work Schedules';

    public ?int $editingScheduleId = null;

    public string $name = '';

    public string $code = '';

    public string $timezone = 'Asia/Karachi';

    public string $scheduleType = 'standard';

    public int $graceMinutes = 5;

    public int $cycleLengthWeeks = 1;

    /** @var array<int, array<string, mixed>> */
    public array $days = [];

    public ?int $assignmentScheduleId = null;

    public string $assignmentTargetType = 'employee';

    public ?int $assignmentEmployeeId = null;

    /** @var array<int, int> */
    public array $assignmentEmployeeIds = [];

    public string $assignmentEmployeeSearch = '';

    public ?int $assignmentTeamId = null;

    public string $assignmentEffectiveFrom = '';

    public string $assignmentEffectiveTo = '';

    public string $assignmentReason = '';

    public ?int $assignmentGraceMinutesOverride = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(HrAuthorization::class)->allows($user, HrPermission::WorkScheduleManage);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->days = $this->defaultDays(1);
    }

    public function updatedCycleLengthWeeks(): void
    {
        if ($this->editingScheduleId === null) {
            $this->days = $this->defaultDays(max(1, min(12, $this->cycleLengthWeeks)));
        }
    }

    public function editPattern(int $scheduleId): void
    {
        $schedule = WorkSchedule::query()->with('days')->findOrFail($scheduleId);
        $this->editingScheduleId = $schedule->id;
        $this->name = $schedule->name;
        $this->code = $schedule->code;
        $this->timezone = $schedule->timezone;
        $this->scheduleType = $schedule->schedule_type;
        $this->graceMinutes = (int) ($schedule->grace_minutes ?? $schedule->attendancePolicy?->grace_minutes ?? 0);
        $this->cycleLengthWeeks = $schedule->cycle_length_weeks;
        $this->days = $schedule->days->isEmpty() ? $this->defaultDays($schedule->cycle_length_weeks) : $schedule->days->map(fn ($day): array => [
            'cycle_week' => $day->cycle_week, 'weekday' => $day->weekday, 'is_working_day' => $day->is_working_day,
            'expected_start_time' => substr((string) $day->expected_start_time, 0, 5), 'expected_end_time' => substr((string) $day->expected_end_time, 0, 5),
            'break_start_time' => $day->break_start_time ? substr($day->break_start_time, 0, 5) : null,
            'break_end_time' => $day->break_end_time ? substr($day->break_end_time, 0, 5) : null,
            'earns_compensatory_off' => $day->earns_compensatory_off,
        ])->all();
    }

    public function saveSchedule(): void
    {
        try {
            if ($this->editingScheduleId !== null) {
                app(WorkScheduleService::class)->update(WorkSchedule::query()->findOrFail($this->editingScheduleId), [
                    'name' => $this->name, 'code' => $this->code, 'timezone' => $this->timezone,
                    'schedule_type' => $this->scheduleType, 'cycle_length_weeks' => $this->cycleLengthWeeks,
                    'grace_minutes' => $this->graceMinutes,
                ], $this->days, $this->user());
            } else {
                app(WorkScheduleService::class)->create([
                    'name' => $this->name, 'code' => $this->code, 'timezone' => $this->timezone,
                    'schedule_type' => $this->scheduleType, 'cycle_length_weeks' => $this->cycleLengthWeeks,
                    'grace_minutes' => $this->graceMinutes,
                ], $this->days, $this->user());
            }
            $this->resetScheduleForm();
            Notification::make()->success()->title('Work Schedule saved')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->showErrors($exception);
        }
    }

    public function assignSchedule(): void
    {
        try {
            if ($this->assignmentTargetType === 'employee') {
                $employeeIds = array_values(array_unique(array_map('intval', $this->assignmentEmployeeIds)));
                if ($employeeIds === [] && $this->assignmentEmployeeId !== null) {
                    $employeeIds = [$this->assignmentEmployeeId];
                }
                $assignments = app(WorkScheduleService::class)->assignEmployees([
                    'work_schedule_id' => $this->assignmentScheduleId,
                    'employee_ids' => $employeeIds,
                    'effective_from' => $this->assignmentEffectiveFrom,
                    'effective_to' => $this->assignmentEffectiveTo ?: null,
                    'grace_minutes_override' => $this->assignmentGraceMinutesOverride,
                    'reason' => $this->assignmentReason,
                ], $this->user());
                $count = count($assignments);
            } else {
                app(WorkScheduleService::class)->assign([
                    'work_schedule_id' => $this->assignmentScheduleId,
                    'employee_id' => null,
                    'team_id' => $this->assignmentTeamId,
                    'effective_from' => $this->assignmentEffectiveFrom,
                    'effective_to' => $this->assignmentEffectiveTo ?: null,
                    'grace_minutes_override' => null,
                    'reason' => $this->assignmentReason,
                ], $this->user());
                $count = 1;
            }
            $this->reset('assignmentScheduleId', 'assignmentEmployeeId', 'assignmentEmployeeIds', 'assignmentEmployeeSearch', 'assignmentTeamId', 'assignmentEffectiveFrom', 'assignmentEffectiveTo', 'assignmentReason', 'assignmentGraceMinutesOverride');
            Notification::make()->success()->title($count === 1 ? 'Schedule assigned' : 'Schedules assigned')->body($count === 1 ? 'Assignment history was recorded.' : "{$count} Employee assignments were saved together.")->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'assignment');
        }
    }

    public function selectVisibleEmployees(): void
    {
        $this->user();
        $visibleIds = Employee::query()->where('status', true)
            ->when(trim($this->assignmentEmployeeSearch) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.trim($this->assignmentEmployeeSearch).'%')
                ->orWhere('employee_id', 'like', '%'.trim($this->assignmentEmployeeSearch).'%')))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assignmentEmployeeIds = array_values(array_unique(array_merge($this->assignmentEmployeeIds, $visibleIds)));
    }

    public function toggleStatus(int $scheduleId): void
    {
        $schedule = WorkSchedule::query()->findOrFail($scheduleId);
        try {
            app(WorkScheduleService::class)->setStatus($schedule, ! $schedule->status, $this->user());
            Notification::make()->success()->title('Schedule status updated')->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Cannot change Schedule status')->body(collect($exception->errors())->flatten()->first())->send();
        }
    }

    public function endAssignmentAction(): Action
    {
        return Action::make('endAssignment')
            ->label('End Assignment')
            ->color('danger')
            ->size('xs')
            ->modalHeading('End Work Schedule Assignment')
            ->modalSubmitActionLabel('End Assignment')
            ->fillForm(function (array $arguments): array {
                $assignment = $this->assignment((int) ($arguments['assignmentId'] ?? 0));
                $defaultEnd = today()->lessThan($assignment->effective_from)
                    ? $assignment->effective_from
                    : today();

                return [
                    'target' => $assignment->employee?->name ?? 'Team — '.$assignment->team?->name,
                    'current_range' => $assignment->effective_from->format('d M Y').' – '.($assignment->effective_to?->format('d M Y') ?? 'Open'),
                    'effective_from' => $assignment->effective_from->toDateString(),
                    'current_effective_to' => $assignment->effective_to?->toDateString(),
                    'end_date' => $defaultEnd->toDateString(),
                ];
            })
            ->schema([
                TextInput::make('target')->label('Assigned Employee / Team')->disabled()->dehydrated(false),
                TextInput::make('current_range')->label('Current Effective Range')->disabled()->dehydrated(false),
                DatePicker::make('end_date')->label('End Date')->required()
                    ->helperText('The assignment remains effective through this date.'),
                Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->columnSpanFull(),
            ])
            ->action(function (array $data, array $arguments): void {
                app(WorkScheduleService::class)->endAssignment(
                    $this->assignment((int) ($arguments['assignmentId'] ?? 0)),
                    (string) $data['end_date'],
                    (string) $data['reason'],
                    $this->user(),
                );
                Notification::make()->success()->title('Schedule assignment ended')->send();
            });
    }

    public function getViewData(): array
    {
        $activeEmployees = Employee::query()->where('status', true)->get(['id', 'team_id']);
        $currentAssignments = WorkScheduleAssignment::query()
            ->whereDate('effective_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->whereHas('schedule', fn ($query) => $query->where('status', true))
            ->get(['employee_id', 'team_id']);
        $directEmployeeIds = $currentAssignments->pluck('employee_id')->filter()->map(fn ($id): int => (int) $id);
        $assignedTeamIds = $currentAssignments->pluck('team_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        $assignedEmployeeIds = $directEmployeeIds->merge($activeEmployees->whereIn('team_id', $assignedTeamIds)->pluck('id'))->unique();
        $hasCompanyDefault = DB::table('company_work_schedule_versions as versions')
            ->join('work_schedules as schedules', 'schedules.id', '=', 'versions.work_schedule_id')
            ->where('schedules.status', true)->whereDate('versions.effective_from', '<=', today('Asia/Karachi'))->exists();
        $search = trim($this->assignmentEmployeeSearch);
        $employeeQuery = Employee::query()->where('status', true);
        if ($search !== '' || $this->assignmentEmployeeIds !== []) {
            $employeeQuery->where(function ($query) use ($search): void {
                if ($search !== '') {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('employee_id', 'like', '%'.$search.'%');
                }
                if ($this->assignmentEmployeeIds !== []) {
                    $query->orWhereIn('id', $this->assignmentEmployeeIds);
                }
            });
        }

        return [
            'activeScheduleCount' => WorkSchedule::query()->where('status', true)->count(),
            'assignedEmployeeCount' => $hasCompanyDefault ? $activeEmployees->count() : $activeEmployees->whereIn('id', $assignedEmployeeIds)->count(),
            'unassignedEmployeeCount' => $hasCompanyDefault ? 0 : max(0, $activeEmployees->count() - $activeEmployees->whereIn('id', $assignedEmployeeIds)->count()),
            'schedules' => WorkSchedule::query()->withCount([
                'days',
                'assignments as current_assignments_count' => fn ($query) => $query
                    ->whereDate('effective_from', '<=', today())
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today())),
                'assignments as ended_assignments_count' => fn ($query) => $query->whereDate('effective_to', '<', today()),
            ])->with(['attendancePolicy:id,grace_minutes', 'days', 'assignments' => fn ($query) => $query->with(['employee:id,name,employee_id', 'team:id,name'])->latest('effective_from')->limit(20)])->orderBy('name')->get(),
            'employees' => $employeeQuery->orderBy('name')->get(['id', 'name', 'employee_id']),
            'teams' => Team::query()->where('status', true)->orderBy('name')->get(['id', 'name']),
            'weekdays' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            'today' => today(),
        ];
    }

    private function resetScheduleForm(): void
    {
        $this->reset('editingScheduleId', 'name', 'code');
        $this->timezone = 'Asia/Karachi';
        $this->scheduleType = 'standard';
        $this->graceMinutes = 5;
        $this->cycleLengthWeeks = 1;
        $this->days = $this->defaultDays(1);
        $this->resetValidation();
    }

    /** @return array<int, array<string, mixed>> */
    private function defaultDays(int $weeks): array
    {
        $days = [];
        foreach (range(1, $weeks) as $week) {
            foreach (range(0, 6) as $weekday) {
                $working = $weekday !== 0;
                $days[] = [
                    'cycle_week' => $week, 'weekday' => $weekday, 'is_working_day' => $working,
                    'expected_start_time' => $working ? '09:00' : null, 'expected_end_time' => $weekday === 6 ? '16:00' : ($working ? '17:00' : null),
                    'break_start_time' => null, 'break_end_time' => null, 'earns_compensatory_off' => false,
                ];
            }
        }

        return $days;
    }

    private function showErrors(ValidationException $exception, string $prefix = ''): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $name = $prefix === '' ? str($field)->camel()->toString() : $prefix.str($field)->studly()->toString();
            $this->addError($name, $messages[0]);
        }
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }

    private function assignment(int $assignmentId): WorkScheduleAssignment
    {
        $this->user();

        return WorkScheduleAssignment::query()->with(['employee:id,name', 'team:id,name'])->findOrFail($assignmentId);
    }
}
