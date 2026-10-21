<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\HistoricalLeaveEvent;
use App\Models\HistoricalLeaveImportBatch;
use App\Models\HistoricalLeaveRecord;
use App\Models\HistoricalLeaveRecordDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Writes historical leave separately from normal LeaveRequest records. Batch import is orchestrated by HistoricalLeaveImportService. */
class HistoricalLeaveService
{
    public function __construct(
        private readonly HistoricalLeaveAuthorization $access,
        private readonly HistoricalLeavePreviewService $previewer,
        private readonly AnnualLeaveCycleResolver $cycles,
        private readonly JoiningDateLeaveBalanceService $balances,
        private readonly LeaveService $leaveService,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return Collection<int, HistoricalLeaveRecord>
     */
    public function record(array $input, User $actor): Collection
    {
        return $this->write($input, $actor, HrPermission::LeaveHistoricalRecord, 'manual', null, null);
    }

    /** @param array<string,mixed> $input @return Collection<int,HistoricalLeaveRecord> */
    public function recordFromImport(array $input, User $actor, HistoricalLeaveImportBatch $batch, int $sourceRowNumber): Collection
    {
        if ($batch->uploaded_by_user_id !== $actor->id || $batch->status !== 'importing') {
            throw new AuthorizationException;
        }

        return $this->write($input, $actor, HrPermission::LeaveHistoricalImport, 'import', $batch->id, $sourceRowNumber);
    }

    public function hasExactExistingRecord(int $employeeId, int $leaveTypeId, string $fromDate, string $toDate): bool
    {
        return HistoricalLeaveRecord::query()->select('record_group_uuid')
            ->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->groupBy('record_group_uuid')
            ->havingRaw('DATE(MIN(from_date)) = ?', [$fromDate])
            ->havingRaw('DATE(MAX(to_date)) = ?', [$toDate])
            ->exists();
    }

    /** @param array<string,mixed> $input @return Collection<int,HistoricalLeaveRecord> */
    private function write(array $input, User $actor, HrPermission $requiredPermission, string $source, ?int $importBatchId, ?int $sourceRowNumber): Collection
    {
        $data = Validator::make($input, [
            'employee_id' => ['required', 'integer'],
            'actual_joining_date' => ['nullable', 'date'],
            'leave_type_id' => ['required', 'integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'chargeable_days' => ['nullable', 'numeric', 'gt:0', 'max:366'],
            'manual_override_reason' => ['nullable', 'string', 'max:2000'],
            'approval_status' => ['required', 'in:pending,approved,rejected,cancelled'],
            'reason' => ['required', 'string', 'max:2000'],
            'historical_source' => ['required', 'string', 'max:120'],
            'original_reference' => ['nullable', 'string', 'max:255'],
            'original_approved_at' => ['nullable', 'date'],
            'original_approver' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'idempotency_key' => ['required', 'uuid'],
            'confirmed' => ['required', 'accepted'],
        ])->validate();

        $employee = $this->access->employeeQuery($actor, $requiredPermission, excludeSelf: true)
            ->whereKey($data['employee_id'])->first();
        throw_unless($employee instanceof Employee, AuthorizationException::class);

        return DB::transaction(function () use ($data, $actor, $employee, $requiredPermission, $source, $importBatchId, $sourceRowNumber): Collection {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if (! $this->access->canRecordEmployee($actor, $lockedEmployee, $requiredPermission)) {
                throw new AuthorizationException;
            }
            if ($source === 'import' && ($lockedEmployee->joining_date === null
                || $lockedEmployee->joining_date->toDateString() !== ($data['actual_joining_date'] ?? null))) {
                throw ValidationException::withMessages(['actual_joining_date' => 'The employee’s verified joining date changed after preview. Re-preview the workbook.']);
            }
            $existing = HistoricalLeaveRecord::query()->where('record_group_uuid', $data['idempotency_key'])
                ->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                $this->assertIdempotentReplayMatches($existing, $data);

                return $existing->load('days');
            }

            if ($source === 'import' && $this->hasExactExistingRecord(
                $lockedEmployee->id, (int) $data['leave_type_id'], $data['from_date'], $data['to_date'],
            )) {
                throw ValidationException::withMessages(['from_date' => 'An exact historical employee, leave type, and date-range record already exists, regardless of its approval status.']);
            }

            $preview = $this->previewer->preview($data, $actor, $requiredPermission);
            if ($preview['employee_id'] !== $lockedEmployee->id) {
                throw new AuthorizationException;
            }

            $records = new Collection;
            foreach ($preview['segments'] as $segment) {
                $start = $segment['start'];
                $cycle = $this->cycles->forDate($lockedEmployee, $start);
                $type = LeaveType::query()->whereKey($data['leave_type_id'])->firstOrFail();
                $approved = $data['approval_status'] === 'approved';
                $record = HistoricalLeaveRecord::query()->create([
                    'reference' => $this->reference(),
                    'record_group_uuid' => $data['idempotency_key'],
                    'employee_id' => $lockedEmployee->id,
                    'leave_type_id' => $type->id,
                    'leave_policy_id' => $this->balances->policyForCycle($cycle, $start)->id,
                    'from_date' => $segment['start']->toDateString(),
                    'to_date' => $segment['end']->toDateString(),
                    'leave_period_start' => $cycle->start->toDateString(),
                    'leave_period_end' => $cycle->end->toDateString(),
                    'chargeable_days' => $segment['days'],
                    'calculation_method' => $preview['calculation_method'],
                    'manual_override_reason' => $preview['manual_override_reason'],
                    'approval_status' => $data['approval_status'],
                    'reason' => trim($data['reason']),
                    'original_reference' => filled($data['original_reference'] ?? null) ? trim($data['original_reference']) : null,
                    'historical_source' => trim($data['historical_source']),
                    'remarks' => filled($data['remarks'] ?? null) ? trim($data['remarks']) : null,
                    'source' => $source,
                    'import_batch_id' => $importBatchId,
                    'source_row_number' => $sourceRowNumber,
                    'original_approved_at' => $data['original_approved_at'] ?? null,
                    'original_approver' => filled($data['original_approver'] ?? null) ? trim($data['original_approver']) : null,
                    'recorded_by_user_id' => $actor->id,
                    'recorded_at' => now(),
                    'approved_by_user_id' => $approved && $source === 'manual' ? $actor->id : null,
                    'approved_at' => $approved ? ($source === 'manual' ? now() : ($data['original_approved_at'] ?? null)) : null,
                    'idempotency_key' => (string) Str::uuid(),
                ]);

                foreach ($segment['daily_rows'] as $day) {
                    HistoricalLeaveRecordDay::query()->create([
                        'historical_leave_record_id' => $record->id,
                        ...$day,
                    ]);
                }

                HistoricalLeaveEvent::query()->create([
                    'historical_leave_record_id' => $record->id,
                    'actor_user_id' => $actor->id,
                    'event_type' => $source === 'import' ? 'imported' : 'recorded',
                    'previous_values' => null,
                    'new_values' => [
                        'approval_status' => $record->approval_status,
                        'from_date' => $record->from_date->toDateString(),
                        'to_date' => $record->to_date->toDateString(),
                        'chargeable_days' => (string) $record->chargeable_days,
                        'leave_policy_id' => $record->leave_policy_id,
                    ],
                    'reason' => trim($data['reason']),
                    'source' => $source,
                    'occurred_at' => now(),
                    'idempotency_key' => (string) Str::uuid(),
                ]);

                if ($approved) {
                    $dates = collect($segment['daily_rows'])
                        ->where('counts_as_leave', true)
                        ->pluck('leave_date')
                        ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
                        ->all();
                    $this->leaveService->reconcileApprovedHistoricalLeave(
                        $lockedEmployee, $type, $dates, $record->reference, $actor,
                    );
                }

                $this->activity->log($source === 'import' ? 'hr.historical_leave_imported' : 'hr.historical_leave_recorded', $actor, $record, [
                    'reference' => $record->reference,
                    'employee_id' => $lockedEmployee->id,
                    'leave_type_id' => $type->id,
                    'approval_status' => $record->approval_status,
                    'from_date' => $record->from_date->toDateString(),
                    'to_date' => $record->to_date->toDateString(),
                    'leave_days' => (string) $record->chargeable_days,
                    'source' => $source,
                ]);
                $records->push($record);
            }

            return $records->load('days');
        }, 3);
    }

    /** @param Collection<int, HistoricalLeaveRecord> $records @param array<string, mixed> $data */
    private function assertIdempotentReplayMatches(Collection $records, array $data): void
    {
        $sameRequest = $records->every(fn (HistoricalLeaveRecord $record): bool => $record->employee_id === (int) $data['employee_id']
            && $record->leave_type_id === (int) $data['leave_type_id']
            && $record->reason === trim($data['reason'])
            && $record->historical_source === trim($data['historical_source'])
                && $record->approval_status === $data['approval_status']
            && $record->original_reference === (filled($data['original_reference'] ?? null) ? trim($data['original_reference']) : null)
        );
        if (! $sameRequest
            || $records->min(fn (HistoricalLeaveRecord $record): string => $record->from_date->toDateString()) !== CarbonImmutable::parse($data['from_date'])->toDateString()
            || $records->max(fn (HistoricalLeaveRecord $record): string => $record->to_date->toDateString()) !== CarbonImmutable::parse($data['to_date'])->toDateString()) {
            throw ValidationException::withMessages(['idempotency_key' => 'This confirmation key was already used for a different historical leave record.']);
        }
    }

    private function reference(): string
    {
        do {
            $reference = 'HLE-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        } while (HistoricalLeaveRecord::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
