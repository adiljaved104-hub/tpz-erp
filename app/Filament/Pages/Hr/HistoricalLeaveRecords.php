<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\HistoricalLeaveImportBatch;
use App\Models\LeaveCycleAdjustment;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\HistoricalLeaveAuthorization;
use App\Services\Hr\HistoricalLeaveImportService;
use App\Services\Hr\HistoricalLeavePreviewService;
use App\Services\Hr\HistoricalLeaveService;
use App\Services\Hr\JoiningDateLeaveBalanceService;
use App\Services\Hr\LeaveCycleAdjustmentService;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class HistoricalLeaveRecords extends Page
{
    use WithFileUploads;
    use WithPagination;

    protected string $view = 'filament.pages.hr.historical-leave-records';

    protected static ?string $slug = 'hr/historical-leave-records';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Historical Leave';

    protected static ?string $title = 'Historical Leave Records';

    public string $employeeSearch = '';

    public ?int $employeeId = null;

    public ?int $leaveTypeId = null;

    public string $fromDate = '';

    public string $toDate = '';

    public string $chargeableDays = '';

    public string $manualOverrideReason = '';

    public string $approvalStatus = 'pending';

    public string $historicalSource = '';

    public string $originalReference = '';

    public string $originalApprovedAt = '';

    public string $originalApprover = '';

    public string $reason = '';

    public string $remarks = '';

    public string $idempotencyKey = '';

    public bool $confirmed = false;

    public $importFile = null;

    public ?array $importPreview = null;

    public bool $confirmImport = false;

    public string $historyEmployeeFilter = '';

    public string $historyStatusFilter = '';

    public string $adjustmentEmployeeSearch = '';

    public ?int $adjustmentEmployeeId = null;

    public string $adjustmentCycleStart = '';

    public string $adjustmentType = 'credit';

    public string $adjustmentDays = '';

    public string $adjustmentReason = '';

    public string $adjustmentSupportingReference = '';

    public string $adjustmentIdempotencyKey = '';

    public bool $adjustmentConfirmed = false;

    public ?array $preview = null;

    public ?string $previewError = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(HrAuthorization::class)->allows($user, HrPermission::LeaveHistoricalView)
            || app(HrAuthorization::class)->allows($user, HrPermission::LeaveHistoricalRecord)
            || app(HrAuthorization::class)->allows($user, HrPermission::LeaveHistoricalImport)
            || app(HrAuthorization::class)->allows($user, HrPermission::LeaveHistoricalAdjust);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->idempotencyKey = (string) Str::uuid();
        $this->adjustmentIdempotencyKey = (string) Str::uuid();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['leaveTypeId', 'fromDate', 'toDate', 'chargeableDays', 'manualOverrideReason', 'approvalStatus'], true)) {
            $this->refreshPreview();
        }
    }

    public function updatedEmployeeSearch(): void
    {
        $this->employeeId = null;
        $this->refreshPreview();
    }

    public function updatedHistoryEmployeeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedImportFile(): void
    {
        $this->importPreview = null;
        $this->confirmImport = false;
        $this->resetValidation('importFile', 'confirmImport');
    }

    public function updatedAdjustmentEmployeeSearch(): void
    {
        $this->adjustmentEmployeeId = null;
        $this->adjustmentCycleStart = '';
        $this->resetValidation();
    }

    public function selectAdjustmentEmployee(int $employeeId): void
    {
        $employee = app(HistoricalLeaveAuthorization::class)
            ->employeeQuery($this->user(), HrPermission::LeaveHistoricalAdjust, excludeSelf: true)
            ->whereKey($employeeId)->first();
        abort_unless($employee instanceof Employee, 403);
        $this->adjustmentEmployeeId = $employee->id;
        $this->adjustmentEmployeeSearch = $employee->employee_id.' · '.$employee->name;
    }

    public function adjustmentEmployees(): Collection
    {
        if (! $this->canAdjust()) {
            return collect();
        }
        $search = trim($this->adjustmentEmployeeSearch);
        $query = app(HistoricalLeaveAuthorization::class)
            ->employeeQuery($this->user(), HrPermission::LeaveHistoricalAdjust, excludeSelf: true);
        if ($search !== '') {
            $query->where(fn ($employees) => $employees->where('name', 'like', "%{$search}%")
                ->orWhere('employee_id', 'like', "%{$search}%"));
        }

        return $query->orderBy('name')->limit(15)->get(['id', 'employee_id', 'name']);
    }

    public function updatedAdjustmentCycleStart(): void
    {
        $this->resetValidation('adjustmentCycleStart', 'adjustmentDays');
    }

    public function adjustmentCycle(): ?array
    {
        if ($this->adjustmentEmployeeId === null || $this->adjustmentCycleStart === '' || ! $this->canAdjust()) {
            return null;
        }
        $employee = app(HistoricalLeaveAuthorization::class)
            ->employeeQuery($this->user(), HrPermission::LeaveHistoricalAdjust, excludeSelf: true)
            ->whereKey($this->adjustmentEmployeeId)->first();
        if (! $employee instanceof Employee) {
            return null;
        }
        try {
            $cycle = app(AnnualLeaveCycleResolver::class)->forDate($employee, $this->adjustmentCycleStart);
            $balance = app(JoiningDateLeaveBalanceService::class)->forDate($employee, $cycle->start);
        } catch (ValidationException $exception) {
            return ['error' => (string) collect($exception->errors())->flatten()->first()];
        }

        return [
            'start' => $cycle->start->toDateString(), 'end' => $cycle->end->toDateString(),
            'entitlement' => $balance->entitlement, 'adjustments' => $balance->adjustments,
            'remaining' => $balance->remaining, 'used' => $balance->used, 'pending' => $balance->pending,
        ];
    }

    public function downloadImportTemplate(): mixed
    {
        abort_unless($this->canImport(), 403);

        return app(HistoricalLeaveImportService::class)->downloadTemplate($this->user());
    }

    public function previewImport(): void
    {
        abort_unless($this->canImport(), 403);
        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        try {
            $this->importPreview = app(HistoricalLeaveImportService::class)->preview($this->importFile, $this->user());
            $this->confirmImport = false;
            $this->resetValidation('confirmImport');
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->addError('importFile', $messages[0]);
            }
            $this->importPreview = null;
        }
    }

    public function downloadRejectedImportRows(): mixed
    {
        abort_unless($this->canImport() && $this->importFile instanceof UploadedFile && is_array($this->importPreview), 403);

        $freshPreview = app(HistoricalLeaveImportService::class)->preview($this->importFile, $this->user());
        abort_unless(hash_equals((string) ($this->importPreview['file_sha256'] ?? ''), $freshPreview['file_sha256']), 409);

        return app(HistoricalLeaveImportService::class)->downloadRejectedRows($freshPreview, $this->user());
    }

    public function confirmImportBatch(): void
    {
        abort_unless($this->canImport(), 403);
        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx', 'max:10240'], 'confirmImport' => ['accepted']]);
        if (! $this->importFile instanceof UploadedFile || ! is_array($this->importPreview)) {
            throw ValidationException::withMessages(['importFile' => 'Preview a workbook before confirming its import.']);
        }

        try {
            $batch = app(HistoricalLeaveImportService::class)->import(
                $this->importFile, (string) ($this->importPreview['file_sha256'] ?? ''), $this->user(),
            );
            $this->reset('importFile', 'importPreview', 'confirmImport');
            Notification::make()->success()->title('Historical leave imported')->body($batch->imported_rows.' rows were recorded and audited.')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->addError('importFile', $messages[0]);
            }
        }
    }

    public function recordCycleAdjustment(): void
    {
        abort_unless($this->canAdjust(), 403);
        $cycle = $this->adjustmentCycle();
        if ($cycle === null) {
            throw ValidationException::withMessages(['adjustmentCycleStart' => 'Select an authorized employee and a valid anniversary cycle date first.']);
        }
        try {
            app(LeaveCycleAdjustmentService::class)->record([
                'employee_id' => $this->adjustmentEmployeeId,
                'leave_period_start' => $cycle['start'], 'leave_period_end' => $cycle['end'],
                'adjustment_type' => $this->adjustmentType, 'days' => $this->adjustmentDays,
                'reason' => $this->adjustmentReason, 'supporting_reference' => $this->adjustmentSupportingReference,
                'idempotency_key' => $this->adjustmentIdempotencyKey, 'confirmed' => $this->adjustmentConfirmed,
            ], $this->user());
            $this->reset('adjustmentEmployeeSearch', 'adjustmentEmployeeId', 'adjustmentCycleStart', 'adjustmentDays', 'adjustmentReason', 'adjustmentSupportingReference', 'adjustmentConfirmed');
            $this->adjustmentType = 'credit';
            $this->adjustmentIdempotencyKey = (string) Str::uuid();
            Notification::make()->success()->title('Leave cycle adjustment recorded')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $map = [
                    'employee_id' => 'adjustmentEmployeeId', 'leave_period_start' => 'adjustmentCycleStart',
                    'leave_period_end' => 'adjustmentCycleStart', 'adjustment_type' => 'adjustmentType',
                    'days' => 'adjustmentDays', 'reason' => 'adjustmentReason',
                    'supporting_reference' => 'adjustmentSupportingReference', 'confirmed' => 'adjustmentConfirmed',
                ];
                $this->addError($map[$field] ?? 'adjustmentReason', $messages[0]);
            }
        }
    }

    public function selectEmployee(int $employeeId): void
    {
        $employee = app(HistoricalLeaveAuthorization::class)
            ->employeeQuery($this->user(), HrPermission::LeaveHistoricalRecord, excludeSelf: true)
            ->whereKey($employeeId)->first();
        abort_unless($employee instanceof Employee, 403);
        $this->employeeId = $employee->id;
        $this->employeeSearch = $employee->employee_id.' · '.$employee->name;
        $this->refreshPreview();
    }

    public function employees(): Collection
    {
        $search = trim($this->employeeSearch);
        $query = app(HistoricalLeaveAuthorization::class)
            ->employeeQuery($this->user(), HrPermission::LeaveHistoricalRecord, excludeSelf: true);
        if ($search !== '') {
            $query->where(fn ($employees) => $employees->where('name', 'like', "%{$search}%")
                ->orWhere('employee_id', 'like', "%{$search}%"));
        }

        return $query->orderBy('name')->limit(15)->get(['id', 'employee_id', 'name']);
    }

    public function refreshPreview(): void
    {
        $this->preview = null;
        $this->previewError = null;
        if ($this->employeeId === null || $this->leaveTypeId === null || $this->fromDate === '' || $this->toDate === '') {
            return;
        }

        try {
            $this->preview = app(HistoricalLeavePreviewService::class)->preview($this->formData(), $this->user());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->previewError = collect($exception->errors())->flatten()->first();
        }
    }

    public function recordHistoricalLeave(): void
    {
        try {
            $records = app(HistoricalLeaveService::class)->record($this->formData() + [
                'reason' => $this->reason,
                'historical_source' => $this->historicalSource,
                'original_reference' => $this->originalReference,
                'original_approved_at' => $this->originalApprovedAt !== '' ? $this->originalApprovedAt : null,
                'original_approver' => $this->originalApprover,
                'remarks' => $this->remarks,
                'idempotency_key' => $this->idempotencyKey,
                'confirmed' => $this->confirmed,
            ], $this->user());
            $this->resetForm();
            Notification::make()->success()->title($records->count() === 1 ? 'Historical leave recorded' : 'Historical leave recorded across leave periods')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $map = [
                    'employee_id' => 'employeeId', 'leave_type_id' => 'leaveTypeId', 'from_date' => 'fromDate',
                    'to_date' => 'toDate', 'chargeable_days' => 'chargeableDays',
                    'manual_override_reason' => 'manualOverrideReason', 'approval_status' => 'approvalStatus',
                    'historical_source' => 'historicalSource', 'original_reference' => 'originalReference',
                    'original_approved_at' => 'originalApprovedAt', 'original_approver' => 'originalApprover',
                    'reason' => 'reason', 'remarks' => 'remarks', 'idempotency_key' => 'idempotencyKey',
                    'confirmed' => 'confirmed',
                ];
                $this->addError($map[$field] ?? 'reason', $messages[0]);
            }
        }
    }

    #[Computed]
    public function records()
    {
        return $this->recordsQuery()->latest('recorded_at')->paginate(20);
    }

    #[Computed]
    public function employeeBalances()
    {
        abort_unless($this->canViewHistory(), 403);
        $query = app(HistoricalLeaveAuthorization::class)
            ->employeeQuery($this->user(), HrPermission::LeaveHistoricalView);
        if (filled($this->historyEmployeeFilter)) {
            $search = trim($this->historyEmployeeFilter);
            $query->where(fn ($employees) => $employees->where('employee_id', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        return $query->orderBy('name')->paginate(20, ['*'], 'balancePage')->through(fn (Employee $employee): array => $this->balanceRow($employee));
    }

    #[Computed]
    public function recentImportBatches()
    {
        abort_unless($this->canImport(), 403);

        return HistoricalLeaveImportBatch::query()->where('uploaded_by_user_id', $this->user()->id)
            ->withCount('records')->latest()->limit(10)->get();
    }

    #[Computed]
    public function recentAdjustments()
    {
        abort_unless($this->canAdjust(), 403);
        $employeeIds = app(HistoricalLeaveAuthorization::class)
            ->employeeQuery($this->user(), HrPermission::LeaveHistoricalAdjust, excludeSelf: true)->select('id');

        return LeaveCycleAdjustment::query()->whereIn('employee_id', $employeeIds)
            ->with(['employee:id,employee_id,name', 'recorder:id,name'])->latest('recorded_at')->limit(20)->get();
    }

    public function downloadHistoryExport(): mixed
    {
        abort_unless($this->canViewHistory(), 403);
        $query = $this->recordsQuery()->with(['employee:id,employee_id,name,joining_date', 'type:id,name', 'recorder:id,name', 'approver:id,name']);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Reference', 'Employee ID', 'Employee', 'Verified Joining Date', 'Leave Type', 'From Date', 'To Date', 'Anniversary Period Start', 'Anniversary Period End', 'Chargeable Days', 'Approval Status', 'Historical Source', 'Original Reference', 'Original Approval Date', 'Original Approver', 'Recorded By', 'Recorded At', 'Source', 'Import Row']);
            $query->reorder('id')->chunkById(500, function ($records) use ($output): void {
                foreach ($records as $record) {
                    fputcsv($output, array_map(fn ($value): string => $this->safeCsv((string) ($value ?? '')), [
                        $record->reference, $record->employee->employee_id, $record->employee->name,
                        $record->employee->joining_date?->toDateString(), $record->type->name,
                        $record->from_date->toDateString(), $record->to_date->toDateString(),
                        $record->leave_period_start->toDateString(), $record->leave_period_end->toDateString(),
                        $record->chargeable_days, $record->approval_status, $record->historical_source,
                        $record->original_reference, $record->original_approved_at?->toDateString(),
                        $record->original_approver, $record->recorder->name, $record->recorded_at->toDateTimeString(),
                        $record->source, $record->source_row_number,
                    ]));
                }
            });
            fclose($output);
        }, 'historical_leave_records_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function recordsQuery()
    {
        $query = app(HistoricalLeaveAuthorization::class)->visibleRecords($this->user())
            ->with(['employee:id,employee_id,name', 'type:id,name', 'recorder:id,name', 'approver:id,name', 'batch:id,batch_uuid,original_filename']);
        if (filled($this->historyEmployeeFilter)) {
            $search = trim($this->historyEmployeeFilter);
            $query->whereHas('employee', fn ($employees) => $employees->where('employee_id', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }
        if (in_array($this->historyStatusFilter, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $query->where('approval_status', $this->historyStatusFilter);
        }

        return $query;
    }

    /** @return array<string,mixed> */
    private function balanceRow(Employee $employee): array
    {
        try {
            $balances = app(JoiningDateLeaveBalanceService::class);
            $cycles = app(AnnualLeaveCycleResolver::class);
            $currentCycle = $cycles->forDate($employee, CarbonImmutable::today());
            $current = $balances->forDate($employee, CarbonImmutable::today());
            $previous = [];
            $cursor = $currentCycle->start->subDay();
            for ($period = 0; $period < 3 && $cursor->gte($employee->joining_date); $period++) {
                $cycle = $cycles->forDate($employee, $cursor);
                $balance = $balances->forDate($employee, $cycle->end);
                $previous[] = [
                    'start' => $cycle->start->toDateString(), 'end' => $cycle->end->toDateString(),
                    'entitlement' => $balance->entitlement, 'historical_used' => $balance->historicalUsed,
                    'current_used' => $balance->currentUsed, 'pending' => $balance->pending, 'remaining' => $balance->remaining,
                ];
                $cursor = $cycle->start->subDay();
            }

            return [
                'employee' => $employee, 'current' => $current, 'current_cycle' => $currentCycle,
                'previous' => $previous, 'error' => null,
            ];
        } catch (ValidationException $exception) {
            return ['employee' => $employee, 'current' => null, 'current_cycle' => null, 'previous' => [],
                'error' => (string) collect($exception->errors())->flatten()->first()];
        }
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    #[Computed]
    public function leaveTypes()
    {
        return LeaveType::query()->whereIn('code', ['annual', 'unpaid'])->where('status', true)->orderBy('name')->get();
    }

    public function canRecord(): bool
    {
        return app(HrAuthorization::class)->allows($this->user(), HrPermission::LeaveHistoricalRecord);
    }

    public function canApprove(): bool
    {
        return app(HrAuthorization::class)->allows($this->user(), HrPermission::LeaveApprove);
    }

    public function canImport(): bool
    {
        return app(HrAuthorization::class)->allows($this->user(), HrPermission::LeaveHistoricalImport);
    }

    public function canAdjust(): bool
    {
        return app(HrAuthorization::class)->allows($this->user(), HrPermission::LeaveHistoricalAdjust);
    }

    public function canViewHistory(): bool
    {
        return app(HrAuthorization::class)->allows($this->user(), HrPermission::LeaveHistoricalView);
    }

    private function formData(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'leave_type_id' => $this->leaveTypeId,
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'chargeable_days' => $this->chargeableDays !== '' ? $this->chargeableDays : null,
            'manual_override_reason' => $this->manualOverrideReason,
            'approval_status' => $this->approvalStatus,
        ];
    }

    private function resetForm(): void
    {
        $this->reset(
            'employeeSearch', 'employeeId', 'leaveTypeId', 'fromDate', 'toDate', 'chargeableDays',
            'manualOverrideReason', 'historicalSource', 'originalReference', 'originalApprovedAt',
            'originalApprover', 'reason', 'remarks', 'preview', 'previewError', 'confirmed',
        );
        $this->approvalStatus = 'pending';
        $this->idempotencyKey = (string) Str::uuid();
        $this->resetValidation();
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
