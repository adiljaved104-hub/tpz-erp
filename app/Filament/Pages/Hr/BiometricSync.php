<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\BiometricAttendanceEvent;
use App\Models\BiometricAttendanceSyncRun;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hikvision\BiometricEmployeeMappingService;
use App\Services\Hikvision\HikvisionAttendanceImporter;
use App\Services\Hikvision\HikvisionIsapiClient;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class BiometricSync extends Page
{
    protected string $view = 'filament.pages.hr.biometric-sync';

    protected static ?string $slug = 'hr/biometric-sync';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Biometric Sync';

    /** @var array<string, mixed>|null */
    public ?array $connectionResult = null;

    public string $backfillFrom = '';

    public string $backfillTo = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(HrAuthorization::class)->allows($user, HrPermission::BiometricManage);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->backfillFrom = now('Asia/Karachi')->startOfDay()->format('Y-m-d\TH:i');
        $this->backfillTo = now('Asia/Karachi')->format('Y-m-d\TH:i');
    }

    public function testConnection(): void
    {
        $this->authorizePage();
        $result = app(HikvisionIsapiClient::class)->testConnection();
        $this->connectionResult = [
            'connected' => $result->connected,
            'status' => $result->status,
            'message' => $result->message,
            'metadata' => $result->metadata,
        ];
        Notification::make()
            ->title($result->status)
            ->body($result->message)
            ->color($result->connected ? 'success' : 'danger')
            ->send();
    }

    public function syncNow(): void
    {
        $this->authorizePage();
        $this->runSafely(function (): void {
            $run = app(HikvisionAttendanceImporter::class)->syncNow($this->user())->run;
            Notification::make()->success()->title('Biometric sync finished')->body($this->resultMessage($run))->send();
        });
    }

    public function backfill(): void
    {
        $this->authorizePage();
        $validated = $this->validate([
            'backfillFrom' => ['required', 'date'],
            'backfillTo' => ['required', 'date', 'after_or_equal:backfillFrom'],
        ]);
        $this->runSafely(function () use ($validated): void {
            $run = app(HikvisionAttendanceImporter::class)->backfill(
                CarbonImmutable::parse($validated['backfillFrom'], 'Asia/Karachi'),
                CarbonImmutable::parse($validated['backfillTo'], 'Asia/Karachi'),
                $this->user(),
            )->run;
            $this->dispatch('close-modal', id: 'hikvision-backfill');
            Notification::make()->success()->title('Backfill finished')->body($this->resultMessage($run))->send();
        });
    }

    public function mapEmployeeAction(): Action
    {
        return Action::make('mapEmployee')
            ->label('Map Employee')
            ->modalHeading('Map Hikvision Employee')
            ->modalSubmitActionLabel('Confirm Mapping')
            ->fillForm(function (array $arguments): array {
                $this->authorizePage();
                $context = $this->unmappedContext((string) ($arguments['externalIdentifier'] ?? ''));

                return [
                    'hikvision_employee_no' => $context['external_identifier'],
                    'hikvision_name' => $context['employee_name'],
                ];
            })
            ->schema([
                TextInput::make('hikvision_employee_no')->label('Hikvision Employee No.')->disabled()->dehydrated(false),
                TextInput::make('hikvision_name')->label('Hikvision Name')->disabled()->dehydrated(false),
                Select::make('employee_id')->label('ERP Employee')->required()->searchable()->preload()
                    ->options(fn (): array => Employee::query()->where('status', true)->orderBy('name')->get(['id', 'name', 'employee_id'])
                        ->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->name} · {$employee->employee_id}"])->all()),
                Textarea::make('reason')->label('Reason')->required()->maxLength(2000),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->authorizePage();
                $context = $this->unmappedContext((string) ($arguments['externalIdentifier'] ?? ''));
                $employee = Employee::query()->where('status', true)->find($data['employee_id']);
                if ($employee === null) {
                    throw ValidationException::withMessages(['employee_id' => 'Select an active ERP Employee.']);
                }

                try {
                    $result = app(BiometricEmployeeMappingService::class)->map(
                        (string) config('hikvision.source'),
                        $context['external_identifier'],
                        $employee,
                        $this->user(),
                        $data['reason'],
                    );
                    $body = "Employee mapped successfully. {$result->processedEventCount} event(s) processed";
                    $body .= $result->pendingEventCount > 0
                        ? "; {$result->pendingEventCount} event(s) pending. ".$this->pendingReasonSummary($result->pendingReasons)
                        : '; no events are pending.';
                    Notification::make()->success()->title('Employee mapped')->body($body)->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Employee could not be mapped')->body(collect($exception->errors())->flatten()->first())->send();

                    throw $exception;
                }
            });
    }

    public function reprocessPendingAttendanceAction(): Action
    {
        return Action::make('reprocessPendingAttendance')
            ->label('Reprocess Pending Attendance')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Reprocess pending biometric Attendance?')
            ->modalDescription('Processes mapped biometric evidence that has not yet produced Attendance, usually because a valid employee mapping or Work Schedule was missing earlier. Existing Attendance is not rebuilt. No device request will be made.')
            ->modalSubmitActionLabel('Reprocess Pending Attendance')
            ->action(function (): void {
                $this->authorizePage();
                $result = app(BiometricEmployeeMappingService::class)
                    ->reprocessAllPendingAttendance((string) config('hikvision.source'));

                if ($result->processedEventCount > 0) {
                    Notification::make()->success()->title('Pending Attendance reprocessed')
                        ->body("Processed {$result->processedEventCount} event(s) across {$result->processedDayCount} day(s). Pending: {$result->pendingEventCount}.")
                        ->send();

                    return;
                }
                if ($result->pendingEventCount > 0) {
                    Notification::make()->warning()->title('Attendance remains pending')
                        ->body("Pending: {$result->pendingEventCount}. ".$this->pendingReasonSummary($result->pendingReasons))
                        ->send();

                    return;
                }

                Notification::make()->success()->title('No pending Attendance evidence')->body('All mapped biometric evidence is already processed.')->send();
            });
    }

    public function rebuildMappedAttendanceAction(): Action
    {
        return Action::make('rebuildMappedAttendance')
            ->label('Rebuild Biometric Attendance')
            ->color('gray')
            ->modalHeading('Rebuild mapped biometric Attendance')
            ->modalDescription('Recalculate existing Attendance for selected employees and dates using current attendance processing rules. Use this only when existing Attendance needs correction. Normal future schedule changes do not require a rebuild. Original biometric events are preserved and are not edited by this action.')
            ->modalSubmitActionLabel('Confirm Rebuild')
            ->fillForm(function (): array {
                $this->authorizePage();

                return [
                    'select_all' => false,
                    'employee_ids' => [],
                    'date_from' => today('Asia/Karachi')->toDateString(),
                    'date_to' => today('Asia/Karachi')->toDateString(),
                ];
            })
            ->schema([
                Toggle::make('select_all')->label('Select All Active Employees')->live()
                    ->helperText('Off by default. Turn this on only when every active Employee should be included.'),
                Select::make('employee_ids')
                    ->label('Employees')
                    ->multiple()
                    ->required(fn (Get $get): bool => ! $get('select_all'))
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Employee::query()
                        ->where('status', true)
                        ->orderBy('name')
                        ->get(['id', 'name', 'employee_id'])
                        ->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->name} · {$employee->employee_id}"])
                        ->all())
                    ->live()
                    ->helperText('Choose one or more active ERP Employees. Select All is never enabled automatically.'),
                DatePicker::make('date_from')->label('Date From')->required()->live(),
                DatePicker::make('date_to')->label('Date To')->required()->afterOrEqual('date_from')->live(),
                Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->columnSpanFull(),
                Placeholder::make('confirmation_summary')->label('Confirmation')->columnSpanFull()
                    ->content(function (Get $get): string {
                        $employeeCount = $get('select_all')
                            ? Employee::query()->where('status', true)->count()
                            : count(array_unique(array_filter((array) $get('employee_ids'))));
                        $from = filled($get('date_from')) ? CarbonImmutable::parse($get('date_from'), 'Asia/Karachi') : null;
                        $to = filled($get('date_to')) ? CarbonImmutable::parse($get('date_to'), 'Asia/Karachi') : null;
                        $dates = $from !== null && $to !== null && $to->greaterThanOrEqualTo($from)
                            ? $from->diffInDays($to) + 1
                            : 0;

                        return "You are about to rebuild Attendance for {$employeeCount} employee(s) from ".($from?->format('d M Y') ?? '—').' to '.($to?->format('d M Y') ?? '—')." ({$dates} calendar date(s)). Existing Attendance results in this range may change. Original biometric events will not be modified.";
                    }),
            ])
            ->action(function (array $data): void {
                $this->authorizePage();
                $employeeIds = ($data['select_all'] ?? false)
                    ? Employee::query()->where('status', true)->pluck('id')->all()
                    : array_values(array_unique(array_map('intval', (array) ($data['employee_ids'] ?? []))));
                if ($employeeIds === []) {
                    throw ValidationException::withMessages(['employee_ids' => 'Select at least one active Employee or explicitly use Select All.']);
                }
                $employees = Employee::query()->where('status', true)->whereKey($employeeIds)->orderBy('id')->get();
                if ($employees->count() !== count($employeeIds)) {
                    throw ValidationException::withMessages(['employee_ids' => 'One or more selected Employees are no longer active or eligible.']);
                }
                $dateFrom = CarbonImmutable::parse($data['date_from'], 'Asia/Karachi')->startOfDay();
                $dateTo = CarbonImmutable::parse($data['date_to'], 'Asia/Karachi')->startOfDay();

                $result = app(BiometricEmployeeMappingService::class)->rebuildMappedAttendanceBatch(
                    $employees,
                    $dateFrom,
                    $dateTo,
                    (string) config('hikvision.source'),
                    $this->user(),
                    (string) $data['reason'],
                );
                $body = "Employees selected: {$result->employeeCount}; dates: {$result->calendarDateCount}; Attendance rebuilt: {$result->rebuiltCount}; unchanged: {$result->unchangedCount}; skipped: {$result->skippedCount}; failed: {$result->failedCount}.";
                if ($result->pendingReasons !== []) {
                    $body .= ' '.$this->pendingReasonSummary($result->pendingReasons);
                }
                Notification::make()->success()->title('Biometric Attendance rebuilt')->body($body)->send();
            });
    }

    public function getViewData(): array
    {
        $source = (string) config('hikvision.source');
        $device = trim((string) config('hikvision.host')).':'.(int) config('hikvision.port');
        $base = BiometricAttendanceSyncRun::query()->where('source', $source)->where('device_identifier', $device);

        return [
            'enabled' => (bool) config('hikvision.enabled'),
            'scheduledSyncEnabled' => (bool) config('hikvision.scheduled_sync_enabled'),
            'host' => (string) config('hikvision.host'),
            'port' => (int) config('hikvision.port'),
            'lastAttempt' => (clone $base)->latest('attempted_at')->latest('id')->first(),
            'lastSuccessful' => (clone $base)->where('status', 'successful')->latest('completed_at')->latest('id')->first(),
            'unmapped' => app(BiometricEmployeeMappingService::class)->unmappedQueue($source),
            'maximumBackfillDays' => (int) config('hikvision.maximum_backfill_days', 31),
        ];
    }

    /** @return array{external_identifier: string, employee_name: string} */
    private function unmappedContext(string $externalIdentifier): array
    {
        $externalIdentifier = trim($externalIdentifier);
        $event = BiometricAttendanceEvent::query()
            ->where('source', (string) config('hikvision.source'))
            ->where('external_employee_identifier', $externalIdentifier)
            ->whereDoesntHave('employeeMappings', fn ($query) => $query->whereNull('superseded_at'))
            ->latest('punched_at')
            ->latest('id')
            ->first();
        if ($event === null) {
            throw ValidationException::withMessages(['employee_id' => 'This Hikvision Employee is no longer available for mapping.']);
        }

        return [
            'external_identifier' => $externalIdentifier,
            'employee_name' => (string) (data_get($event->raw_metadata, 'employee_name') ?: 'Not provided'),
        ];
    }

    private function runSafely(callable $operation): void
    {
        try {
            $operation();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            Notification::make()->danger()->title('Biometric operation could not continue')->body(collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function resultMessage(BiometricAttendanceSyncRun $run): string
    {
        return "Imported {$run->imported_count}; duplicates {$run->duplicate_count}; skipped {$run->skipped_count}; unmapped {$run->unmapped_count}.";
    }

    /** @param array<string, int> $reasons */
    private function pendingReasonSummary(array $reasons): string
    {
        return collect([
            'no_schedule_assignment' => isset($reasons['no_schedule_assignment']) ? "No schedule assignment: {$reasons['no_schedule_assignment']} event(s)." : null,
            'schedule_pattern_missing' => isset($reasons['schedule_pattern_missing']) ? "Assigned schedule has no complete day pattern: {$reasons['schedule_pattern_missing']} event(s)." : null,
            'attendance_validation' => isset($reasons['attendance_validation']) ? "Other Attendance validation: {$reasons['attendance_validation']} event(s)." : null,
        ])->filter()->implode(' ');
    }

    private function authorizePage(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function user(): User
    {
        $this->authorizePage();

        return auth()->user();
    }
}
