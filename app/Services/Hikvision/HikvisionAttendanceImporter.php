<?php

namespace App\Services\Hikvision;

use App\Contracts\AttendanceSourceImporter;
use App\DTOs\Attendance\AttendanceImportEvent;
use App\DTOs\Hikvision\HikvisionSyncResult;
use App\Enums\HrPermission;
use App\Exceptions\HikvisionAttendanceApplicationException;
use App\Exceptions\HikvisionTransportException;
use App\Models\BiometricAttendanceEvent;
use App\Models\BiometricAttendanceSyncRun;
use App\Models\BiometricEmployeeIdentifier;
use App\Models\BiometricEventEmployeeMapping;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\BiometricAttendanceProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class HikvisionAttendanceImporter implements AttendanceSourceImporter
{
    public function __construct(
        private readonly HikvisionIsapiClient $client,
        private readonly HikvisionEventNormalizer $normalizer,
        private readonly BiometricAttendanceProcessor $attendance,
        private readonly HrAuthorization $authorization,
        private readonly HikvisionErrorSanitizer $sanitizer,
    ) {}

    public function source(): string
    {
        return (string) config('hikvision.source', 'hikvision_isapi');
    }

    /** @return iterable<AttendanceImportEvent> */
    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        foreach ($this->pages($from, $to) as $rawEvent) {
            $event = $this->normalizer->classify($rawEvent, $this->source(), $this->deviceIdentifier())->event;
            if ($event !== null) {
                yield $event;
            }
        }
    }

    public function syncNow(User $actor): HikvisionSyncResult
    {
        $this->authorize($actor);
        [$from, $to] = $this->incrementalWindow();

        return $this->sync('manual', $from, $to, $actor);
    }

    public function syncScheduled(): ?HikvisionSyncResult
    {
        if (! config('hikvision.enabled') || ! config('hikvision.scheduled_sync_enabled')) {
            return null;
        }
        [$from, $to] = $this->incrementalWindow();
        $now ??= CarbonImmutable::now('Asia/Karachi');
        $now = $now->subSeconds(
    max(0, (int) config('hikvision.sync_end_lag_seconds', 60))
);

        return $this->sync('incremental', $from, $to, null);
    }

    public function backfill(CarbonImmutable $from, CarbonImmutable $to, User $actor): HikvisionSyncResult
    {
        $this->authorize($actor);
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['backfillFrom' => 'The From date must be before the To date.']);
        }
        if ($from->diffInDays($to) > (int) config('hikvision.maximum_backfill_days', 31)) {
            throw ValidationException::withMessages(['backfillTo' => 'Backfill cannot exceed '.config('hikvision.maximum_backfill_days', 31).' days per request.']);
        }

        return $this->sync('backfill', $from, $to, $actor);
    }

  /** @return array{CarbonImmutable, CarbonImmutable} */
public function incrementalWindow(?CarbonImmutable $now = null): array
{
    $timezone = 'Asia/Karachi';

    $now ??= CarbonImmutable::now($timezone);

    $now = $now
        ->shiftTimezone($timezone)
        ->subSeconds(
            max(0, (int) config('hikvision.sync_end_lag_seconds', 60))
        );

    $last = BiometricAttendanceSyncRun::query()
        ->where('source', $this->source())
        ->where('device_identifier', $this->deviceIdentifier())
        ->where('status', 'successful')
        ->latest('completed_at')
        ->latest('id')
        ->first();

    $from = $last?->window_to
        ? $last->window_to
            ->shiftTimezone($timezone)
            ->subMinutes(
                max(0, (int) config('hikvision.sync_overlap_minutes', 10))
            )
        : $now->subHours(
            max(1, (int) config('hikvision.first_sync_lookback_hours', 8))
        );

    return [$from, $now];
}

    private function sync(string $mode, CarbonImmutable $from, CarbonImmutable $to, ?User $actor): HikvisionSyncResult
    {
        if (! config('hikvision.enabled')) {
            throw ValidationException::withMessages(['sync' => 'Hikvision integration is disabled.']);
        }
        $lock = $this->lock();
        if (! $lock->get()) {
            throw ValidationException::withMessages(['sync' => 'A Hikvision synchronization is already running for this device.']);
        }

        try {
            return $this->performSync($mode, $from, $to, $actor);
        } finally {
            $lock->release();
        }
    }

    private function performSync(string $mode, CarbonImmutable $from, CarbonImmutable $to, ?User $actor): HikvisionSyncResult
    {
        $run = BiometricAttendanceSyncRun::query()->create([
            'source' => $this->source(),
            'device_identifier' => $this->deviceIdentifier(),
            'mode' => $mode,
            'window_from' => $from,
            'window_to' => $to,
            'status' => 'running',
            'attempted_at' => now(),
            'initiated_by_user_id' => $actor?->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $counts = ['imported_count' => 0, 'duplicate_count' => 0, 'skipped_count' => 0, 'unmapped_count' => 0];
        $partial = false;
        $safeCode = null;
        $safeMessage = null;

        try {
            foreach ($this->pages($from, $to) as $rawEvent) {
                $event = $this->normalizer->classify($rawEvent, $this->source(), $this->deviceIdentifier())->event;
                if ($event === null) {
                    $counts['skipped_count']++;

                    continue;
                }
                try {
                    $result = $this->persist($event);
                    $counts[$result.'_count']++;
                } catch (HikvisionAttendanceApplicationException $exception) {
                    $partial = true;
                    $counts[$exception->evidenceResult.'_count']++;
                    $safeCode ??= 'attendance_processing_failed';
                    $safeMessage ??= 'One or more imported events could not be applied to daily Attendance.';
                }
            }
        } catch (HikvisionTransportException $exception) {
            $safeCode = $exception->safeCode;
            $safeMessage = $exception->safeMessage;
            $status = array_sum($counts) > 0 ? 'partial' : 'failed';

            return new HikvisionSyncResult($this->finalize($run, $status, $counts, $safeCode, $safeMessage));
        } catch (Throwable $exception) {
            $this->finalize($run, 'failed', $counts, 'unexpected_error', 'Synchronization stopped because of an unexpected application error.');

            throw $exception;
        }

        return new HikvisionSyncResult($this->finalize(
            $run,
            $partial ? 'partial' : 'successful',
            $counts,
            $safeCode,
            $safeMessage,
        ));
    }

    /** @return 'imported'|'duplicate'|'unmapped' */
    private function persist(AttendanceImportEvent $event): string
    {
        $raw = BiometricAttendanceEvent::query()
            ->where('source', $event->source)
            ->where('device_identifier', $event->deviceIdentifier)
            ->where('source_event_id', $event->sourceEventId)
            ->first();
        if ($raw !== null) {
            try {
                $this->rebuildIfNeeded($raw);
            } catch (ValidationException) {
                throw new HikvisionAttendanceApplicationException('duplicate');
            }

            return 'duplicate';
        }

        try {
            $raw = BiometricAttendanceEvent::query()->create([
                'source' => $event->source,
                'source_event_id' => $event->sourceEventId,
                'external_employee_identifier' => $event->externalEmployeeIdentifier,
                'device_identifier' => $event->deviceIdentifier,
                'punched_at' => $event->punchedAt->utc(),
                'punch_type' => $event->punchType,
                'raw_metadata' => $event->safeMetadata,
                'idempotency_key' => $event->idempotencyKey,
                'imported_at' => now(),
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            $raw = BiometricAttendanceEvent::query()
                ->where('source', $event->source)
                ->where('device_identifier', $event->deviceIdentifier)
                ->where('source_event_id', $event->sourceEventId)
                ->firstOrFail();
            try {
                $this->rebuildIfNeeded($raw);
            } catch (ValidationException) {
                throw new HikvisionAttendanceApplicationException('duplicate');
            }

            return 'duplicate';
        }

        $identifier = BiometricEmployeeIdentifier::query()
            ->where('source', $event->source)
            ->where('external_employee_identifier', $event->externalEmployeeIdentifier)
            ->where('status', true)
            ->with('employee')
            ->first();
        if ($identifier === null || ! $identifier->employee?->status) {
            return 'unmapped';
        }

        $this->mapEvent($raw, $identifier);
        try {
            $this->attendance->rebuild($identifier->employee, $event->punchedAt->setTimezone('Asia/Karachi')->startOfDay());
        } catch (ValidationException) {
            throw new HikvisionAttendanceApplicationException('imported');
        }

        return 'imported';
    }

    private function rebuildIfNeeded(BiometricAttendanceEvent $event): void
    {
        $mapping = $event->employeeMappings()->whereNull('superseded_at')->with('employee')->first();
        if ($mapping?->employee?->status
            && ! DB::table('attendance_evidence_links')->where('biometric_attendance_event_id', $event->id)->exists()) {
            $this->attendance->rebuild($mapping->employee, $event->punched_at->setTimezone('Asia/Karachi')->startOfDay());
        }
    }

    private function mapEvent(BiometricAttendanceEvent $event, BiometricEmployeeIdentifier $identifier): void
    {
        BiometricEventEmployeeMapping::query()->firstOrCreate(
            ['active_fingerprint' => 'biometric-event:'.$event->id],
            [
                'biometric_attendance_event_id' => $event->id,
                'employee_id' => $identifier->employee_id,
                'biometric_employee_identifier_id' => $identifier->id,
                'mapped_by_user_id' => $identifier->mapped_by_user_id,
                'reason' => 'Resolved through active biometric Employee identifier.',
                'mapped_at' => now(),
                'idempotency_key' => (string) Str::uuid(),
                'created_at' => now(),
            ],
        );
    }

    /** @return iterable<int, array<string, mixed>> */
    private function pages(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $position = 0;
        $searchId = (string) Str::uuid();
        $limit = max(1, min(1000, (int) config('hikvision.page_size', 100)));
        $maximumPages = max(1, (int) config('hikvision.maximum_pages', 200));
        for ($pageNumber = 1; $pageNumber <= $maximumPages; $pageNumber++) {
            $page = $this->client->searchEvents($from, $to, $searchId, $position, $limit);
            foreach ($page->events as $event) {
                yield $event;
            }
            if (! $page->hasMore) {
                return;
            }
            if ($page->nextPosition <= $position) {
                throw new HikvisionTransportException('pagination_invalid', 'The device returned an invalid pagination position.');
            }
            $position = $page->nextPosition;
        }

        throw new HikvisionTransportException('page_limit_reached', 'The configured event-page safety limit was reached.');
    }

    private function finalize(BiometricAttendanceSyncRun $run, string $status, array $counts, ?string $code, ?string $message): BiometricAttendanceSyncRun
    {
        $run->forceFill($counts + [
            'status' => $status,
            'completed_at' => now(),
            'safe_error_code' => $code,
            'safe_error_message' => $message === null ? null : $this->sanitizer->message($message),
        ])->save();

        return $run->refresh();
    }

    private function authorize(User $actor): void
    {
        throw_unless($this->authorization->allows($actor, HrPermission::BiometricManage), AuthorizationException::class);
    }

    private function deviceIdentifier(): string
    {
        return trim((string) config('hikvision.host')).':'.(int) config('hikvision.port', 80);
    }

    private function lock(): Lock
    {
        return Cache::lock('hikvision-sync:'.hash('sha256', $this->source().'|'.$this->deviceIdentifier()), max(60, (int) config('hikvision.timeout', 10) * 10));
    }
}
