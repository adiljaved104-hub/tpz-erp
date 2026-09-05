<?php

namespace App\Services\Hikvision;

use App\DTOs\Hikvision\BiometricAttendanceBulkRebuildResult;
use App\DTOs\Hikvision\BiometricAttendanceReprocessingResult;
use App\DTOs\Hikvision\BiometricEmployeeMappingResult;
use App\Enums\HrPermission;
use App\Models\BiometricAttendanceEvent;
use App\Models\BiometricEmployeeIdentifier;
use App\Models\BiometricEventEmployeeMapping;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\BiometricAttendanceProcessor;
use App\Services\Hr\EffectiveWorkScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BiometricEmployeeMappingService
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly BiometricAttendanceProcessor $attendance,
        private readonly EffectiveWorkScheduleResolver $schedules,
        private readonly ActivityLogger $activity,
    ) {}

    public function map(string $source, string $externalIdentifier, Employee $employee, User $actor, string $reason, bool $confirmRemap = false): BiometricEmployeeMappingResult
    {
        throw_unless($this->authorization->allows($actor, HrPermission::BiometricManage), AuthorizationException::class);
        if (! $employee->status) {
            throw ValidationException::withMessages(['mappingEmployeeId' => 'Only an active Employee can be mapped.']);
        }
        validator(compact('externalIdentifier', 'reason'), [
            'externalIdentifier' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        $processed = DB::transaction(function () use ($source, $externalIdentifier, $employee, $actor, $reason, $confirmRemap): int {
            $identifier = BiometricEmployeeIdentifier::query()
                ->where('source', $source)
                ->where('external_employee_identifier', $externalIdentifier)
                ->lockForUpdate()
                ->first();
            if ($identifier !== null && (int) $identifier->employee_id !== (int) $employee->id && ! $confirmRemap) {
                throw ValidationException::withMessages(['employee_id' => 'This Hikvision Employee is already mapped. Confirm the remapping with a reason.']);
            }
            if ($identifier !== null && (int) $identifier->employee_id === (int) $employee->id) {
                throw ValidationException::withMessages(['employee_id' => 'This Hikvision Employee is already mapped to the selected ERP Employee.']);
            }
            if ($identifier === null) {
                $identifier = BiometricEmployeeIdentifier::query()->create([
                    'employee_id' => $employee->id,
                    'source' => $source,
                    'external_employee_identifier' => $externalIdentifier,
                    'status' => true,
                    'active_fingerprint' => hash('sha256', $source.'|'.$externalIdentifier),
                    'mapped_by_user_id' => $actor->id,
                    'reason' => trim($reason),
                ]);
            } elseif ((int) $identifier->employee_id !== (int) $employee->id) {
                BiometricEventEmployeeMapping::query()
                    ->where('biometric_employee_identifier_id', $identifier->id)
                    ->whereNull('superseded_at')
                    ->update(['superseded_at' => now(), 'superseded_by_user_id' => $actor->id, 'active_fingerprint' => null]);
                $identifier->forceFill([
                    'employee_id' => $employee->id,
                    'mapped_by_user_id' => $actor->id,
                    'reason' => trim($reason),
                    'status' => true,
                ])->save();
            }

            $events = BiometricAttendanceEvent::query()
                ->where('source', $source)
                ->where('external_employee_identifier', $externalIdentifier)
                ->whereDoesntHave('employeeMappings', fn ($query) => $query->whereNull('superseded_at'))
                ->orderBy('punched_at')
                ->get();
            foreach ($events as $event) {
                BiometricEventEmployeeMapping::query()->create([
                    'biometric_attendance_event_id' => $event->id,
                    'employee_id' => $employee->id,
                    'biometric_employee_identifier_id' => $identifier->id,
                    'mapped_by_user_id' => $actor->id,
                    'reason' => trim($reason),
                    'mapped_at' => now(),
                    'active_fingerprint' => 'biometric-event:'.$event->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'created_at' => now(),
                ]);
            }

            return $events->count();
        });

        $reprocessing = $this->reprocessPendingAttendance($employee, $source, $externalIdentifier);
        $this->activity->log('biometric.identifier_mapped', $actor, $employee, [
            'source' => $source,
            'external_employee_identifier' => $externalIdentifier,
            'associated_event_count' => $processed,
            'processed_event_count' => $reprocessing->processedEventCount,
            'pending_event_count' => $reprocessing->pendingEventCount,
            'reason' => trim($reason),
        ]);

        return new BiometricEmployeeMappingResult(
            associatedEventCount: $processed,
            processedEventCount: $reprocessing->processedEventCount,
            pendingEventCount: $reprocessing->pendingEventCount,
            pendingReasons: $reprocessing->pendingReasons,
        );
    }

    public function reprocessPendingAttendance(
        Employee $employee,
        ?string $source = null,
        ?string $externalIdentifier = null,
    ): BiometricAttendanceReprocessingResult {
        $events = BiometricAttendanceEvent::query()
            ->whereHas('employeeMappings', fn ($query) => $query
                ->where('employee_id', $employee->id)
                ->whereNull('superseded_at'))
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->when($externalIdentifier !== null, fn ($query) => $query->where('external_employee_identifier', $externalIdentifier))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('attendance_evidence_links')
                ->whereColumn('attendance_evidence_links.biometric_attendance_event_id', 'biometric_attendance_events.id'))
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn (BiometricAttendanceEvent $event): string => $event->punched_at
                ->setTimezone('Asia/Karachi')->format('Y-m-d'));

        $processed = 0;
        $pending = 0;
        $processedDays = 0;
        $pendingDays = 0;
        $pendingReasons = [];
        foreach ($events as $date => $dayEvents) {
            $day = CarbonImmutable::parse($date, 'Asia/Karachi')->startOfDay();
            $assignment = $this->schedules->assignment($employee, $day);
            if ($assignment === null) {
                $pending += $dayEvents->count();
                $pendingDays++;
                $pendingReasons['no_schedule_assignment'] = ($pendingReasons['no_schedule_assignment'] ?? 0) + $dayEvents->count();

                continue;
            }
            $expectedPatternRows = max(1, (int) $assignment->schedule->cycle_length_weeks) * 7;
            if ($assignment->schedule->days->count() !== $expectedPatternRows) {
                $pending += $dayEvents->count();
                $pendingDays++;
                $pendingReasons['schedule_pattern_missing'] = ($pendingReasons['schedule_pattern_missing'] ?? 0) + $dayEvents->count();

                continue;
            }

            try {
                $this->attendance->rebuild($employee, $day);
                $processed += $dayEvents->count();
                $processedDays++;
            } catch (ValidationException) {
                // The evidence remains mapped and pending until its effective
                // Schedule configuration can interpret this calendar day.
                $pending += $dayEvents->count();
                $pendingDays++;
                $pendingReasons['attendance_validation'] = ($pendingReasons['attendance_validation'] ?? 0) + $dayEvents->count();
            }
        }

        return new BiometricAttendanceReprocessingResult($processed, $pending, $processedDays, $pendingDays, $pendingReasons);
    }

    public function reprocessAllPendingAttendance(?string $source = null): BiometricAttendanceReprocessingResult
    {
        $employeeIds = DB::table('biometric_event_employee_mappings as mappings')
            ->join('biometric_attendance_events as events', 'events.id', '=', 'mappings.biometric_attendance_event_id')
            ->leftJoin('attendance_evidence_links as evidence', 'evidence.biometric_attendance_event_id', '=', 'events.id')
            ->whereNull('mappings.superseded_at')
            ->whereNull('evidence.biometric_attendance_event_id')
            ->when($source !== null, fn ($query) => $query->where('events.source', $source))
            ->distinct()
            ->pluck('mappings.employee_id');

        $processedEvents = 0;
        $pendingEvents = 0;
        $processedDays = 0;
        $pendingDays = 0;
        $pendingReasons = [];
        foreach (Employee::query()->whereKey($employeeIds)->get() as $employee) {
            $result = $this->reprocessPendingAttendance($employee, $source);
            $processedEvents += $result->processedEventCount;
            $pendingEvents += $result->pendingEventCount;
            $processedDays += $result->processedDayCount;
            $pendingDays += $result->pendingDayCount;
            foreach ($result->pendingReasons as $reason => $count) {
                $pendingReasons[$reason] = ($pendingReasons[$reason] ?? 0) + $count;
            }
        }

        return new BiometricAttendanceReprocessingResult(
            $processedEvents,
            $pendingEvents,
            $processedDays,
            $pendingDays,
            $pendingReasons,
        );
    }

    /**
     * Rebuild derived Attendance from already-mapped immutable evidence.
     * Manual overrides remain protected by BiometricAttendanceProcessor.
     */
    public function rebuildMappedAttendance(
        Employee $employee,
        ?string $source = null,
        ?CarbonImmutable $dateFrom = null,
        ?CarbonImmutable $dateTo = null,
    ): BiometricAttendanceReprocessingResult {
        $windowFrom = $dateFrom?->setTimezone('Asia/Karachi')->startOfDay()->utc();
        $windowTo = $dateTo?->setTimezone('Asia/Karachi')->endOfDay()->utc();
        $events = BiometricAttendanceEvent::query()
            ->whereHas('employeeMappings', fn ($query) => $query
                ->where('employee_id', $employee->id)
                ->whereNull('superseded_at'))
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->when($windowFrom !== null, fn ($query) => $query->where('punched_at', '>=', $windowFrom))
            ->when($windowTo !== null, fn ($query) => $query->where('punched_at', '<=', $windowTo))
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn (BiometricAttendanceEvent $event): string => $event->punched_at
                ->setTimezone('Asia/Karachi')->format('Y-m-d'));

        $processed = 0;
        $pending = 0;
        $processedDays = 0;
        $pendingDays = 0;
        $unchangedDays = 0;
        $pendingReasons = [];

        foreach ($events as $date => $dayEvents) {
            $day = CarbonImmutable::parse($date, 'Asia/Karachi')->startOfDay();
            $assignment = $this->schedules->assignment($employee, $day);
            if ($assignment === null) {
                $pending += $dayEvents->count();
                $pendingDays++;
                $pendingReasons['no_schedule_assignment'] = ($pendingReasons['no_schedule_assignment'] ?? 0) + $dayEvents->count();

                continue;
            }
            $expectedPatternRows = max(1, (int) $assignment->schedule->cycle_length_weeks) * 7;
            if ($assignment->schedule->days->count() !== $expectedPatternRows) {
                $pending += $dayEvents->count();
                $pendingDays++;
                $pendingReasons['schedule_pattern_missing'] = ($pendingReasons['schedule_pattern_missing'] ?? 0) + $dayEvents->count();

                continue;
            }

            try {
                $existingAttendance = EmployeeAttendance::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $day->format('Y-m-d'))
                    ->first();
                $this->attendance->rebuild($employee, $day);
                $processed += $dayEvents->count();
                if ($existingAttendance?->is_overridden) {
                    $unchangedDays++;
                } else {
                    $processedDays++;
                }
            } catch (ValidationException) {
                $pending += $dayEvents->count();
                $pendingDays++;
                $pendingReasons['attendance_validation'] = ($pendingReasons['attendance_validation'] ?? 0) + $dayEvents->count();
            }
        }

        return new BiometricAttendanceReprocessingResult($processed, $pending, $processedDays, $pendingDays, $pendingReasons, $unchangedDays);
    }

    /** @param Collection<int, Employee> $employees */
    public function rebuildMappedAttendanceBatch(
        Collection $employees,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        string $source,
        User $actor,
        string $reason,
    ): BiometricAttendanceBulkRebuildResult {
        throw_unless($this->authorization->allows($actor, HrPermission::BiometricManage), AuthorizationException::class);
        validator([
            'employee_ids' => $employees->pluck('id')->all(),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'reason' => $reason,
        ], [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        if ($employees->contains(fn (Employee $employee): bool => ! $employee->status)) {
            throw ValidationException::withMessages(['employee_ids' => 'Only active Employees can be selected.']);
        }

        $rebuilt = 0;
        $unchanged = 0;
        $skipped = 0;
        $failed = 0;
        $pendingReasons = [];
        foreach ($employees as $employee) {
            $result = $this->rebuildMappedAttendance($employee, $source, $dateFrom, $dateTo);
            $rebuilt += $result->processedDayCount;
            $unchanged += $result->unchangedDayCount;
            $skipped += $result->pendingDayCount;
            $failed += $result->failedDayCount;
            foreach ($result->pendingReasons as $pendingReason => $count) {
                $pendingReasons[$pendingReason] = ($pendingReasons[$pendingReason] ?? 0) + $count;
            }
        }

        $calendarDates = $dateFrom->startOfDay()->diffInDays($dateTo->startOfDay()) + 1;
        $this->activity->log('biometric_attendance.bulk_rebuilt', $actor, properties: [
            'employee_count' => $employees->count(),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'calendar_dates' => $calendarDates,
            'rebuilt' => $rebuilt,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'failed' => $failed,
            'reason' => $reason,
        ]);

        return new BiometricAttendanceBulkRebuildResult(
            $employees->count(),
            $calendarDates,
            $rebuilt,
            $unchanged,
            $skipped,
            $failed,
            $pendingReasons,
        );
    }

    /** @return Collection<int, object> */
    public function unmappedQueue(string $source): Collection
    {
        $rows = BiometricAttendanceEvent::query()
            ->where('source', $source)
            ->whereDoesntHave('employeeMappings', fn ($query) => $query->whereNull('superseded_at'))
            ->selectRaw('external_employee_identifier, MIN(punched_at) AS first_seen, MAX(punched_at) AS last_seen, COUNT(*) AS event_count, MAX(id) AS latest_event_id')
            ->groupBy('external_employee_identifier')
            ->orderByDesc('last_seen')
            ->get();
        $latest = BiometricAttendanceEvent::query()->whereKey($rows->pluck('latest_event_id'))->get()->keyBy('id');

        return $rows->each(function (object $row) use ($latest): void {
            $row->employee_name = data_get($latest->get($row->latest_event_id)?->raw_metadata, 'employee_name');
        });
    }
}
