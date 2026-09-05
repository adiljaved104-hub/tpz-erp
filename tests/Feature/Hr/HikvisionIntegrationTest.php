<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Hr\BiometricSync;
use App\Models\AttendancePolicy;
use App\Models\BiometricAttendanceEvent;
use App\Models\BiometricAttendanceSyncRun;
use App\Models\BiometricEmployeeIdentifier;
use App\Models\BiometricEventEmployeeMapping;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hikvision\BiometricEmployeeMappingService;
use App\Services\Hikvision\HikvisionAttendanceImporter;
use App\Services\Hikvision\HikvisionErrorSanitizer;
use App\Services\Hikvision\HikvisionEventNormalizer;
use App\Services\Hikvision\HikvisionIsapiClient;
use App\Services\Hr\WorkScheduleService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HikvisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'hikvision.enabled' => true,
            'hikvision.scheduled_sync_enabled' => true,
            'hikvision.scheme' => 'http',
            'hikvision.host' => 'device.test',
            'hikvision.port' => 80,
            'hikvision.username' => 'safe-user',
            'hikvision.password' => 'super-secret',
            'hikvision.timeout' => 2,
            'hikvision.page_size' => 2,
            'hikvision.maximum_pages' => 5,
            'hikvision.event_query_major' => 0,
            'hikvision.event_query_minor' => 0,
        ]);
    }

    public function test_connection_success_auth_failure_timeout_and_safe_errors(): void
    {
        Http::fakeSequence()
            ->push(['DeviceInfo' => ['model' => 'DS-K1T320MFWX-B', 'firmwareVersion' => 'V3.5.2']])
            ->push(['AcsEventCap' => ['isSupport' => true]])
            ->push([], 401)
            ->pushFailedConnection('Connection timed out for super-secret');
        $connected = app(HikvisionIsapiClient::class)->testConnection();
        $this->assertTrue($connected->connected);
        $this->assertSame('Connected', $connected->status);

        $this->assertSame('Authentication Failed', app(HikvisionIsapiClient::class)->testConnection()->status);

        $this->assertSame('Timeout', app(HikvisionIsapiClient::class)->testConnection()->status);
        $sanitized = app(HikvisionErrorSanitizer::class)->message('password=super-secret Authorization: Digest username=safe-user');
        $this->assertStringNotContainsString('super-secret', $sanitized);
        $this->assertStringNotContainsString('safe-user', $sanitized);
    }

    public function test_event_search_paginates_more_then_final_without_loading_history(): void
    {
        Http::fakeSequence()
            ->push($this->page([$this->rawEvent(1)], 'MORE'))
            ->push($this->page([$this->rawEvent(2)], 'OK'));

        $events = collect(app(HikvisionAttendanceImporter::class)->events(
            CarbonImmutable::parse('2026-08-21 00:00:00+05:00'),
            CarbonImmutable::parse('2026-08-21 23:59:59+05:00'),
        ));

        $this->assertCount(2, $events);
        $this->assertSame('BIO-100', $events->first()->externalEmployeeIdentifier);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => data_get($request->data(), 'AcsEventCond.searchResultPosition') === 1);
        Http::assertSent(fn ($request) => data_get($request->data(), 'AcsEventCond.major') === 0
            && data_get($request->data(), 'AcsEventCond.minor') === 0);
    }

    public function test_employee_authentication_events_under_multiple_event_codes_qualify(): void
    {
        $normalizer = app(HikvisionEventNormalizer::class);
        $face = $this->rawEvent(1);
        $face['major'] = 5;
        $face['minor'] = 75;
        $card = $this->rawEvent(2);
        $card['major'] = 3;
        $card['minor'] = 1024;
        unset($card['attendanceStatus']);
        $card['currentVerifyMode'] = 'cardOrFace';

        $faceResult = $normalizer->classify($face, 'hikvision_isapi', 'device.test:80');
        $cardResult = $normalizer->classify($card, 'hikvision_isapi', 'device.test:80');

        $this->assertTrue($faceResult->qualifies());
        $this->assertTrue($cardResult->qualifies());
        $this->assertSame(5, $faceResult->event?->safeMetadata['major']);
        $this->assertSame(1024, $cardResult->event?->safeMetadata['minor']);
        $this->assertSame('unknown', $cardResult->event?->punchType);
    }

    public function test_skipped_events_are_safely_classified_by_reason(): void
    {
        $normalizer = app(HikvisionEventNormalizer::class);
        $system = ['major' => 1, 'minor' => 2, 'time' => '2026-08-21T09:00:00+05:00'];
        $unsupported = $this->rawEvent(2);
        unset($unsupported['currentVerifyMode'], $unsupported['attendanceStatus']);
        $invalidTime = $this->rawEvent(3, 'not-a-date');
        $malformed = $this->rawEvent(4);
        unset($malformed['major']);

        $this->assertSame('no_employee_identifier', $normalizer->classify($system, 'hikvision_isapi', 'device.test:80')->skipReason);
        $this->assertSame('unsupported_event_code', $normalizer->classify($unsupported, 'hikvision_isapi', 'device.test:80')->skipReason);
        $this->assertSame('invalid_timestamp', $normalizer->classify($invalidTime, 'hikvision_isapi', 'device.test:80')->skipReason);
        $this->assertSame('malformed_event', $normalizer->classify($malformed, 'hikvision_isapi', 'device.test:80')->skipReason);
    }

    public function test_mapped_events_create_immutable_evidence_and_reuse_daily_attendance_processing(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $this->mapIdentifier($staff, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([
            $this->rawEvent(1, '2026-08-21T09:04:00+05:00', 'checkIn'),
            $this->rawEvent(2, '2026-08-21T17:03:00+05:00', 'checkOut'),
        ], 'OK'))]);

        $run = app(HikvisionAttendanceImporter::class)->syncNow($owner)->run;

        $this->assertSame('successful', $run->status);
        $this->assertSame(2, $run->imported_count);
        $this->assertDatabaseCount('biometric_attendance_events', 2);
        $attendance = EmployeeAttendance::query()->where('employee_id', $staff->employee->id)->sole();
        $this->assertSame('present', $attendance->status->value);
        $this->assertSame(479, $attendance->worked_minutes);
        $this->assertSame(0, $attendance->early_departure_minutes);
        $this->assertDatabaseCount('attendance_evidence_links', 2);
        $this->expectException(\LogicException::class);
        BiometricAttendanceEvent::query()->firstOrFail()->delete();
    }

    public function test_offset_aware_punches_are_stored_in_utc_and_displayed_once_in_schedule_timezone(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $this->mapIdentifier($staff, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([
            $this->rawEvent(1, '2026-08-21T09:24:00+05:00', 'checkIn'),
            $this->rawEvent(2, '2026-08-21T15:59:00+05:00', 'checkOut'),
        ], 'OK'))]);

        app(HikvisionAttendanceImporter::class)->syncNow($owner);

        $events = BiometricAttendanceEvent::query()->orderBy('punched_at')->get();
        $attendance = EmployeeAttendance::query()->sole();
        $rawEvidence = $events->map->getRawOriginal()->all();
        $this->assertSame('2026-08-21 04:24:00', $events->first()->getRawOriginal('punched_at'));
        $this->assertSame('2026-08-21 10:59:00', $events->last()->getRawOriginal('punched_at'));
        $this->assertSame('2026-08-21 04:24:00', $attendance->getRawOriginal('first_check_in_at'));
        $this->assertSame('2026-08-21 10:59:00', $attendance->getRawOriginal('last_check_out_at'));
        $this->assertSame('09:24 AM', $attendance->first_check_in_at->timezone('Asia/Karachi')->format('h:i A'));
        $this->assertSame('03:59 PM', $attendance->last_check_out_at->timezone('Asia/Karachi')->format('h:i A'));
        $this->assertSame(24, $attendance->late_minutes);
        $this->assertSame(61, $attendance->early_departure_minutes);
        CarbonImmutable::setTestNow('2026-08-21 12:00:00');
        try {
            $this->actingAs($owner)->get('/admin/hr/attendance')
                ->assertOk()
                ->assertSee('09:24 AM')
                ->assertSee('03:59 PM');
        } finally {
            CarbonImmutable::setTestNow();
        }

        // Reproduce a legacy derived row containing local wall time in a UTC column,
        // then prove the explicit rebuild repairs it from immutable evidence.
        DB::table('employee_attendances')->where('id', $attendance->id)->update([
            'first_check_in_at' => '2026-08-21 09:24:00',
            'last_check_out_at' => '2026-08-21 15:59:00',
        ]);
        Livewire::actingAs($owner)
            ->test(BiometricSync::class)
            ->callAction('rebuildMappedAttendance', [
                'select_all' => false,
                'employee_ids' => [$staff->employee->id],
                'date_from' => '2026-08-21',
                'date_to' => '2026-08-21',
                'reason' => 'Correct legacy timezone display.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Biometric Attendance rebuilt');

        $attendance->refresh();
        $this->assertSame('2026-08-21 04:24:00', $attendance->getRawOriginal('first_check_in_at'));
        $this->assertSame('2026-08-21 10:59:00', $attendance->getRawOriginal('last_check_out_at'));
        $this->assertSame($rawEvidence, BiometricAttendanceEvent::query()->orderBy('punched_at')->get()->map->getRawOriginal()->all());
    }

    public function test_offset_aware_punch_near_midnight_uses_the_pakistan_attendance_date(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $this->mapIdentifier($staff, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([
            $this->rawEvent(1, '2026-08-21T00:15:00+05:00', 'checkIn'),
            $this->rawEvent(2, '2026-08-21T00:45:00+05:00', 'checkOut'),
        ], 'OK'))]);

        app(HikvisionAttendanceImporter::class)->syncNow($owner);

        $event = BiometricAttendanceEvent::query()->oldest('punched_at')->firstOrFail();
        $attendance = EmployeeAttendance::query()->sole();
        $this->assertSame('2026-08-20 19:15:00', $event->getRawOriginal('punched_at'));
        $this->assertSame('2026-08-21', $attendance->attendance_date->format('Y-m-d'));
        $this->assertSame('12:15 AM', $attendance->first_check_in_at->timezone('Asia/Karachi')->format('h:i A'));
    }

    public function test_missing_checkout_is_not_treated_as_early_checkout(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $this->mapIdentifier($staff, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([
            $this->rawEvent(1, '2026-08-21T09:24:00+05:00', 'checkIn'),
        ], 'OK'))]);

        app(HikvisionAttendanceImporter::class)->syncNow($owner);

        $attendance = EmployeeAttendance::query()->sole();
        $this->assertNull($attendance->last_check_out_at);
        $this->assertSame(0, $attendance->early_departure_minutes);
        $this->assertTrue($attendance->hasMissingCheckout());
        $this->actingAs($owner)->get('/admin/hr/attendance')->assertOk()->assertSee('Missing Check-out');
    }

    public function test_scheduled_off_never_produces_early_checkout(): void
    {
        [$owner, $offDayEmployee] = $this->scheduledPeople();
        $offDayEmployee->employee->workScheduleAssignments()->firstOrFail()->schedule->days()
            ->where('weekday', 5)
            ->update([
                'is_working_day' => false,
                'expected_start_time' => null,
                'expected_end_time' => null,
                'break_start_time' => null,
                'break_end_time' => null,
                'earns_compensatory_off' => false,
            ]);
        $this->mapIdentifier($offDayEmployee, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([
            $this->rawEvent(1, '2026-08-21T09:24:00+05:00', 'checkIn'),
            $this->rawEvent(2, '2026-08-21T15:00:00+05:00', 'checkOut'),
        ], 'OK'))]);
        app(HikvisionAttendanceImporter::class)->syncNow($owner);
        $offAttendance = EmployeeAttendance::query()->sole();
        $this->assertSame('weekend_off', $offAttendance->status->value);
        $this->assertSame(0, $offAttendance->early_departure_minutes);
        $this->assertFalse($offAttendance->hasMissingCheckout());
    }

    public function test_approved_leave_never_produces_early_checkout(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $schedule = $staff->employee->workScheduleAssignments()->firstOrFail()->schedule;
        $leave = LeaveRequest::query()->create([
            'reference' => 'LVR-2026-009998',
            'employee_id' => $staff->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'leave_policy_id' => LeavePolicy::query()->value('id'),
            'from_date' => '2026-08-21',
            'to_date' => '2026-08-21',
            'requested_working_days' => '1.00',
            'status' => 'approved',
            'reason' => 'Approved leave early checkout regression',
            'submitted_at' => now(),
            'decided_by_user_id' => $owner->id,
            'decided_at' => now(),
            'idempotency_key' => fake()->uuid(),
        ]);
        LeaveRequestDay::query()->create([
            'leave_request_id' => $leave->id,
            'leave_date' => '2026-08-21',
            'work_schedule_id' => $schedule->id,
            'classification' => 'working_leave',
            'counts_as_leave' => true,
            'leave_units' => '1.00',
        ]);
        $this->mapIdentifier($staff, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([
            $this->rawEvent(1, '2026-08-21T09:24:00+05:00', 'checkIn'),
            $this->rawEvent(2, '2026-08-21T15:00:00+05:00', 'checkOut'),
        ], 'OK'))]);

        app(HikvisionAttendanceImporter::class)->syncNow($owner);

        $attendance = EmployeeAttendance::query()->sole();
        $this->assertSame('approved_leave', $attendance->status->value);
        $this->assertSame(0, $attendance->early_departure_minutes);
    }

    public function test_irrelevant_and_unmapped_events_have_accurate_counts_and_raw_unmapped_evidence_is_preserved(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $irrelevant = $this->rawEvent(1);
        unset($irrelevant['currentVerifyMode'], $irrelevant['attendanceStatus']);
        Http::fake(['*' => Http::response($this->page([$irrelevant, $this->rawEvent(2)], 'OK'))]);

        $run = app(HikvisionAttendanceImporter::class)->syncNow($owner)->run;

        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(1, $run->unmapped_count);
        $this->assertSame(0, $run->imported_count);
        $this->assertDatabaseCount('biometric_attendance_events', 1);
        $this->assertDatabaseCount('biometric_event_employee_mappings', 0);
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public function test_overlapping_windows_are_idempotent_and_duplicates_do_not_duplicate_attendance(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $this->mapIdentifier($staff, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([$this->rawEvent(1)], 'OK'))]);

        $first = app(HikvisionAttendanceImporter::class)->syncNow($owner)->run;
        $second = app(HikvisionAttendanceImporter::class)->syncNow($owner)->run;

        $this->assertSame(1, $first->imported_count);
        $this->assertSame(1, $second->duplicate_count);
        $this->assertDatabaseCount('biometric_attendance_events', 1);
        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertDatabaseCount('attendance_evidence_links', 1);
    }

    public function test_only_successful_runs_advance_incremental_cursor(): void
    {
        config(['hikvision.sync_end_lag_seconds' => 0]);

        $this->syncRun('successful', '2026-08-20 10:00:00');
        $this->syncRun('partial', '2026-08-21 10:00:00');
        $this->syncRun('failed', '2026-08-21 11:00:00');

        [$from, $to] = app(HikvisionAttendanceImporter::class)->incrementalWindow(CarbonImmutable::parse('2026-08-21 12:00:00'));

        $this->assertSame('2026-08-20 09:50:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-21 12:00:00', $to->format('Y-m-d H:i:s'));
    }

    public function test_transport_failure_finalizes_safe_failed_run_and_never_leaks_credentials(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        Http::fake(['*' => Http::failedConnection('Could not connect password=super-secret')]);

        $run = app(HikvisionAttendanceImporter::class)->syncNow($owner)->run;

        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame('device_unreachable', $run->safe_error_code);
        $this->assertStringNotContainsString('super-secret', (string) $run->safe_error_message);
    }

    public function test_attendance_application_failure_preserves_evidence_and_finishes_partial(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->mapIdentifier($staff, $owner, 'BIO-100');
        Http::fake(['*' => Http::response($this->page([$this->rawEvent(1)], 'OK'))]);

        $run = app(HikvisionAttendanceImporter::class)->syncNow($owner)->run;

        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->imported_count);
        $this->assertDatabaseCount('biometric_attendance_events', 1);
        $this->assertDatabaseCount('employee_attendances', 0);
        $this->assertNotNull($run->completed_at);
    }

    public function test_mapping_reprocesses_unresolved_evidence_once_and_enforces_uniqueness(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        Http::fake(['*' => Http::response($this->page([$this->rawEvent(1)], 'OK'))]);
        app(HikvisionAttendanceImporter::class)->syncNow($owner);

        $result = app(BiometricEmployeeMappingService::class)->map(
            'hikvision_isapi', 'BIO-100', $staff->employee, $owner, 'Verified device enrollment',
        );

        $this->assertSame(1, $result->associatedEventCount);
        $this->assertSame(1, $result->processedEventCount);
        $this->assertSame(0, $result->pendingEventCount);
        $this->assertDatabaseCount('biometric_employee_identifiers', 1);
        $this->assertDatabaseCount('biometric_event_employee_mappings', 1);
        $this->assertDatabaseCount('employee_attendances', 1);
        $other = $this->user(EmployeeRole::Staff);
        $this->expectException(ValidationException::class);
        app(BiometricEmployeeMappingService::class)->map(
            'hikvision_isapi', 'BIO-100', $other->employee, $owner, 'Unconfirmed remap',
        );
    }

    public function test_map_employee_action_mounts_with_device_identity_and_active_employee_options(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $active = $this->user(EmployeeRole::Staff);
        $inactive = $this->user(EmployeeRole::Staff);
        $active->employee->update(['name' => 'Active Mapping Target']);
        $inactive->employee->update(['name' => 'Inactive Mapping Target', 'status' => false]);
        $this->unmappedEvent('BIO-201', 'Device Person');

        $component = Livewire::actingAs($owner)
            ->test(BiometricSync::class)
            ->assertActionExists('mapEmployee', arguments: ['externalIdentifier' => 'BIO-201'])
            ->mountAction('mapEmployee', arguments: ['externalIdentifier' => 'BIO-201'])
            ->assertActionMounted('mapEmployee')
            ->assertActionDataSet([
                'hikvision_employee_no' => 'BIO-201',
                'hikvision_name' => 'Device Person',
            ]);

        $component->callMountedAction([
            'employee_id' => $inactive->employee->id,
            'reason' => 'Inactive Employee must not be eligible.',
        ])->assertHasActionErrors(['employee_id']);

        $this->assertTrue($active->employee->refresh()->status);
    }

    public function test_map_employee_action_reprocesses_evidence_without_mutating_raw_event(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $event = $this->unmappedEvent('BIO-202', 'Mapped Person');
        $before = $event->refresh()->getRawOriginal();

        Livewire::actingAs($owner)
            ->test(BiometricSync::class)
            ->callAction('mapEmployee', [
                'employee_id' => $staff->employee->id,
                'reason' => 'Verified against the device enrollment list.',
            ], ['externalIdentifier' => 'BIO-202'])
            ->assertHasNoActionErrors()
            ->assertNotified('Employee mapped')
            ->assertDontSee('BIO-202');

        $this->assertDatabaseHas('biometric_employee_identifiers', [
            'external_employee_identifier' => 'BIO-202',
            'employee_id' => $staff->employee->id,
        ]);
        $this->assertDatabaseHas('biometric_event_employee_mappings', [
            'biometric_attendance_event_id' => $event->id,
            'employee_id' => $staff->employee->id,
        ]);
        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertSame($before, $event->fresh()->getRawOriginal());
    }

    public function test_mapping_preserves_uncovered_history_and_later_schedule_allows_safe_reprocessing(): void
    {
        [$owner, $staff] = $this->scheduledPeople('2026-08-10');
        $old = $this->unmappedEvent('BIO-204', 'Historical Person', '2026-08-01 04:00:00');
        $covered = $this->unmappedEvent('BIO-204', 'Historical Person', '2026-08-21 04:00:00');
        $oldEvidence = $old->refresh()->getRawOriginal();
        $coveredEvidence = $covered->refresh()->getRawOriginal();

        $result = app(BiometricEmployeeMappingService::class)->map(
            'hikvision_isapi', 'BIO-204', $staff->employee, $owner, 'Verified historical device identity',
        );

        $this->assertSame(2, $result->associatedEventCount);
        $this->assertSame(1, $result->processedEventCount);
        $this->assertSame(1, $result->pendingEventCount);
        $this->assertDatabaseCount('biometric_event_employee_mappings', 2);
        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $staff->employee->id, 'attendance_date' => '2026-08-21 00:00:00']);
        $this->assertDatabaseMissing('employee_attendances', ['employee_id' => $staff->employee->id, 'attendance_date' => '2026-08-01 00:00:00']);
        $this->assertSame($oldEvidence, $old->fresh()->getRawOriginal());
        $this->assertSame($coveredEvidence, $covered->fresh()->getRawOriginal());

        $schedule = WorkSchedule::query()->where('code', 'HIKVISION_TEST')->sole();
        app(WorkScheduleService::class)->assign([
            'work_schedule_id' => $schedule->id,
            'employee_id' => $staff->employee->id,
            'effective_from' => '2026-08-01',
            'effective_to' => '2026-08-09',
            'reason' => 'Verified historical schedule coverage',
        ], $owner);
        $retry = app(BiometricEmployeeMappingService::class)->reprocessPendingAttendance($staff->employee, 'hikvision_isapi', 'BIO-204');

        $this->assertSame(1, $retry->processedEventCount);
        $this->assertSame(0, $retry->pendingEventCount);
        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $staff->employee->id, 'attendance_date' => '2026-08-01 00:00:00']);
        $this->assertDatabaseCount('attendance_evidence_links', 2);
    }

    public function test_reprocess_pending_attendance_action_is_authorized_safe_and_idempotent(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $old = $this->unmappedEvent('BIO-205', 'Pending Person', '2026-08-01 04:00:00');
        $covered = $this->unmappedEvent('BIO-205', 'Pending Person', '2026-08-21 04:00:00');
        $oldEvidence = $old->refresh()->getRawOriginal();
        $coveredEvidence = $covered->refresh()->getRawOriginal();
        $mapping = app(BiometricEmployeeMappingService::class)->map(
            'hikvision_isapi', 'BIO-205', $staff->employee, $owner, 'Verified pending evidence test',
        );
        $this->assertSame(0, $mapping->processedEventCount);
        $this->assertSame(2, $mapping->pendingEventCount);
        $this->assignTestSchedule($owner, $staff->employee, '2026-08-10');

        $component = Livewire::actingAs($owner)
            ->test(BiometricSync::class)
            ->assertActionExists('reprocessPendingAttendance')
            ->callAction('reprocessPendingAttendance')
            ->assertHasNoActionErrors()
            ->assertNotified('Pending Attendance reprocessed');

        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertDatabaseCount('attendance_evidence_links', 1);
        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $staff->employee->id, 'attendance_date' => '2026-08-21 00:00:00']);
        $this->assertDatabaseMissing('employee_attendances', ['employee_id' => $staff->employee->id, 'attendance_date' => '2026-08-01 00:00:00']);
        $this->assertSame($oldEvidence, $old->fresh()->getRawOriginal());
        $this->assertSame($coveredEvidence, $covered->fresh()->getRawOriginal());

        $component->callAction('reprocessPendingAttendance')
            ->assertHasNoActionErrors()
            ->assertNotified('Attendance remains pending');
        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertDatabaseCount('attendance_evidence_links', 1);

        Livewire::actingAs($staff)->test(BiometricSync::class)->assertForbidden();
    }

    public function test_reprocessing_distinguishes_an_assigned_schedule_with_no_day_pattern(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $schedule = WorkSchedule::query()->create([
            'code' => 'EMPTY_PATTERN', 'name' => 'Empty Pattern', 'timezone' => 'Asia/Karachi',
            'schedule_type' => 'standard', 'cycle_length_weeks' => 1,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => true,
        ]);
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id, 'employee_id' => $staff->employee->id,
            'effective_from' => '2026-08-01', 'effective_to' => '2028-09-01',
            'assigned_by_user_id' => $owner->id, 'reason' => 'Legacy empty-pattern assignment',
        ]);
        $this->unmappedEvent('BIO-206', 'Pattern Pending', '2026-08-01 04:00:00');
        $mapping = app(BiometricEmployeeMappingService::class)->map(
            'hikvision_isapi', 'BIO-206', $staff->employee, $owner, 'Verified empty-pattern evidence',
        );

        $this->assertSame(['schedule_pattern_missing' => 1], $mapping->pendingReasons);
        Livewire::actingAs($owner)
            ->test(BiometricSync::class)
            ->callAction('reprocessPendingAttendance')
            ->assertNotified(Notification::make()->warning()->title('Attendance remains pending')
                ->body('Pending: 1. Assigned schedule has no complete day pattern: 1 event(s).'));
        $this->assertDatabaseCount('employee_attendances', 0);
        $this->assertDatabaseCount('attendance_evidence_links', 0);
    }

    public function test_unauthorized_user_cannot_mount_mapping_action_and_duplicate_mapping_is_rejected(): void
    {
        [$owner, $staff] = $this->scheduledPeople();
        $this->unmappedEvent('BIO-203', 'Protected Person');

        Livewire::actingAs($staff)->test(BiometricSync::class)->assertForbidden();
        try {
            app(BiometricEmployeeMappingService::class)->map(
                'hikvision_isapi', 'BIO-203', $staff->employee, $staff, 'Unauthorized mapping attempt',
            );
            $this->fail('An unauthorized biometric mapping was accepted.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('biometric_employee_identifiers', 0);

        app(BiometricEmployeeMappingService::class)->map(
            'hikvision_isapi', 'BIO-203', $staff->employee, $owner, 'Initial verified mapping',
        );

        try {
            app(BiometricEmployeeMappingService::class)->map(
                'hikvision_isapi', 'BIO-203', $staff->employee, $owner, 'Duplicate mapping attempt',
            );
            $this->fail('A duplicate active biometric mapping was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('employee_id', $exception->errors());
        }

        $this->assertDatabaseCount('biometric_employee_identifiers', 1);
        $this->assertDatabaseCount('biometric_event_employee_mappings', 1);
    }

    public function test_manual_page_and_actions_are_authorized_and_staff_is_denied(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);

        $this->actingAs($owner)->get('/admin/hr/biometric-sync')->assertOk();
        $this->actingAs($staff)->get('/admin/hr/biometric-sync')->assertForbidden();
        Livewire::actingAs($owner)->test(BiometricSync::class)->assertSee('Test Connection')->assertSee('Unmapped Employees');
        $this->expectException(AuthorizationException::class);
        app(HikvisionAttendanceImporter::class)->syncNow($staff);
    }

    public function test_scheduler_lock_blocks_overlapping_device_sync_without_creating_a_run(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $key = 'hikvision-sync:'.hash('sha256', 'hikvision_isapi|device.test:80');
        $lock = Cache::lock($key, 60);
        $this->assertTrue($lock->get());
        try {
            app(HikvisionAttendanceImporter::class)->syncNow($owner);
            $this->fail('An overlapping sync was allowed.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('biometric_attendance_sync_runs', 0);
        } finally {
            $lock->release();
        }
    }

    public function test_bulk_rebuild_respects_employee_and_date_selection_and_isolates_schedule_skips(): void
    {
        [$owner, $scheduled] = $this->scheduledPeople();
        $unscheduled = $this->user(EmployeeRole::Staff);
        $this->mappedEvent($scheduled, $owner, 'BIO-BULK-1', '2026-08-20 04:00:00');
        $this->mappedEvent($scheduled, $owner, 'BIO-BULK-1', '2026-08-21 04:00:00');
        $this->mappedEvent($scheduled, $owner, 'BIO-BULK-1', '2026-08-21 12:00:00');
        $this->mappedEvent($unscheduled, $owner, 'BIO-BULK-2', '2026-08-21 04:05:00');
        $eventFingerprint = BiometricAttendanceEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $mappingFingerprint = BiometricEventEmployeeMapping::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        $result = app(BiometricEmployeeMappingService::class)->rebuildMappedAttendanceBatch(
            Employee::query()->whereKey([$scheduled->employee->id, $unscheduled->employee->id])->get(),
            CarbonImmutable::parse('2026-08-21', 'Asia/Karachi'),
            CarbonImmutable::parse('2026-08-21', 'Asia/Karachi'),
            'hikvision_isapi',
            $owner,
            'Focused bulk rebuild test.',
        );

        $this->assertSame(2, $result->employeeCount);
        $this->assertSame(1, $result->calendarDateCount);
        $this->assertSame(1, $result->rebuiltCount);
        $this->assertSame(0, $result->unchangedCount);
        $this->assertSame(1, $result->skippedCount);
        $this->assertSame(0, $result->failedCount);
        $this->assertSame(['no_schedule_assignment' => 1], $result->pendingReasons);
        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $scheduled->employee->id, 'attendance_date' => '2026-08-21 00:00:00']);
        $this->assertDatabaseMissing('employee_attendances', ['employee_id' => $scheduled->employee->id, 'attendance_date' => '2026-08-20 00:00:00']);
        $this->assertDatabaseMissing('employee_attendances', ['employee_id' => $unscheduled->employee->id]);
        Livewire::actingAs($owner)->test(BiometricSync::class)
            ->callAction('rebuildMappedAttendance', [
                'select_all' => false,
                'employee_ids' => [$scheduled->employee->id, $unscheduled->employee->id],
                'date_from' => '2026-08-21',
                'date_to' => '2026-08-21',
                'reason' => 'Explicit multi-Employee rebuild.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()->success()->title('Biometric Attendance rebuilt')
                ->body('Employees selected: 2; dates: 1; Attendance rebuilt: 1; unchanged: 0; skipped: 1; failed: 0. No schedule assignment: 1 event(s).'));
        $this->assertSame($eventFingerprint, BiometricAttendanceEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($mappingFingerprint, BiometricEventEmployeeMapping::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_bulk_rebuild_modal_defaults_to_no_selection_and_select_all_must_be_explicit(): void
    {
        [$owner, $first] = $this->scheduledPeople();
        $second = $this->user(EmployeeRole::Staff);
        $schedule = WorkSchedule::query()->where('code', 'HIKVISION_TEST')->sole();
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id,
            'employee_id' => $second->employee->id,
            'effective_from' => '2026-01-01',
            'assigned_by_user_id' => $owner->id,
            'reason' => 'Second bulk rebuild Employee.',
        ]);
        $this->mappedEvent($first, $owner, 'BIO-ALL-1', '2026-08-21 04:00:00');
        $this->mappedEvent($second, $owner, 'BIO-ALL-2', '2026-08-21 04:05:00');

        $component = Livewire::actingAs($owner)->test(BiometricSync::class)
            ->mountAction('rebuildMappedAttendance')
            ->assertActionMounted('rebuildMappedAttendance')
            ->assertActionDataSet(['select_all' => false, 'employee_ids' => []]);
        $this->assertStringContainsString(
            'Normal future schedule changes do not require a rebuild.',
            $component->instance()->rebuildMappedAttendanceAction()->getModalDescription(),
        );
        $this->assertStringContainsString(
            'Existing Attendance is not rebuilt.',
            $component->instance()->reprocessPendingAttendanceAction()->getModalDescription(),
        );

        $component
            ->setActionData([
                'select_all' => true,
                'employee_ids' => [],
                'date_from' => '2026-08-21',
                'date_to' => '2026-08-21',
                'reason' => 'Explicit all-Employee correction.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()->success()->title('Biometric Attendance rebuilt')
                ->body('Employees selected: 3; dates: 1; Attendance rebuilt: 2; unchanged: 0; skipped: 0; failed: 0.'));

        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $first->employee->id]);
        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $second->employee->id]);
    }

    public function test_unauthorized_user_cannot_run_bulk_rebuild(): void
    {
        [$owner, $staff] = $this->scheduledPeople();

        $this->actingAs($staff)->get('/admin/hr/biometric-sync')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(BiometricEmployeeMappingService::class)->rebuildMappedAttendanceBatch(
            collect([$staff->employee]),
            CarbonImmutable::parse('2026-08-21', 'Asia/Karachi'),
            CarbonImmutable::parse('2026-08-21', 'Asia/Karachi'),
            'hikvision_isapi',
            $staff,
            'Unauthorized rebuild attempt.',
        );
    }

    public function test_scheduler_does_not_contact_device_until_separately_armed(): void
    {
        config()->set('hikvision.scheduled_sync_enabled', false);
        Http::preventStrayRequests();

        $this->assertNull(app(HikvisionAttendanceImporter::class)->syncScheduled());
        $this->assertDatabaseCount('biometric_attendance_sync_runs', 0);
        Http::assertNothingSent();
    }

    /** @return array{User, User} */
    private function scheduledPeople(string $effectiveFrom = '2026-01-01'): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->assignTestSchedule($owner, $staff->employee, $effectiveFrom);

        return [$owner, $staff];
    }

    private function assignTestSchedule(User $owner, Employee $employee, string $effectiveFrom): WorkSchedule
    {
        $schedule = WorkSchedule::query()->create([
            'code' => 'HIKVISION_TEST',
            'name' => 'Hikvision Test',
            'timezone' => 'Asia/Karachi',
            'schedule_type' => 'standard',
            'cycle_length_weeks' => 1,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'status' => true,
        ]);
        foreach (range(0, 6) as $weekday) {
            WorkScheduleDay::query()->create([
                'work_schedule_id' => $schedule->id,
                'cycle_week' => 1,
                'weekday' => $weekday,
                'is_working_day' => true,
                'expected_start_time' => '09:00',
                'expected_end_time' => '17:00',
                'earns_compensatory_off' => false,
            ]);
        }
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id,
            'employee_id' => $employee->id,
            'effective_from' => $effectiveFrom,
            'assigned_by_user_id' => $owner->id,
            'reason' => 'Hikvision test schedule',
        ]);

        return $schedule;
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function mapIdentifier(User $employeeUser, User $actor, string $external): void
    {
        BiometricEmployeeIdentifier::query()->create([
            'employee_id' => $employeeUser->employee->id,
            'source' => 'hikvision_isapi',
            'external_employee_identifier' => $external,
            'status' => true,
            'active_fingerprint' => hash('sha256', 'hikvision_isapi|'.$external),
            'mapped_by_user_id' => $actor->id,
            'reason' => 'Test mapping',
        ]);
    }

    private function unmappedEvent(string $external, string $name, string $punchedAt = '2026-08-21 04:00:00'): BiometricAttendanceEvent
    {
        return BiometricAttendanceEvent::query()->create([
            'source' => 'hikvision_isapi',
            'source_event_id' => 'test-'.Str::uuid(),
            'external_employee_identifier' => $external,
            'device_identifier' => 'device.test:80',
            'punched_at' => $punchedAt,
            'punch_type' => 'check_in',
            'raw_metadata' => ['employee_name' => $name, 'major' => 5, 'minor' => 75],
            'idempotency_key' => (string) Str::uuid(),
            'imported_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function mappedEvent(User $employeeUser, User $actor, string $external, string $punchedAt): BiometricAttendanceEvent
    {
        $event = $this->unmappedEvent($external, $employeeUser->name, $punchedAt);
        BiometricEventEmployeeMapping::query()->create([
            'biometric_attendance_event_id' => $event->id,
            'employee_id' => $employeeUser->employee->id,
            'mapped_by_user_id' => $actor->id,
            'reason' => 'Focused bulk rebuild mapping.',
            'mapped_at' => now(),
            'active_fingerprint' => 'biometric-event:'.$event->id,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return $event;
    }

    /** @return array<string, mixed> */
    private function rawEvent(int $serial, string $time = '2026-08-21T09:00:00+05:00', string $attendanceStatus = 'checkIn'): array
    {
        return [
            'major' => 5,
            'minor' => 75,
            'serialNo' => $serial,
            'employeeNoString' => 'BIO-100',
            'name' => 'Test Employee',
            'time' => $time,
            'attendanceStatus' => $attendanceStatus,
            'currentVerifyMode' => 'fingerPrint',
        ];
    }

    /** @param array<int, array<string, mixed>> $events */
    private function page(array $events, string $status): array
    {
        return ['AcsEvent' => ['responseStatusStrg' => $status, 'InfoList' => $events]];
    }

    private function syncRun(string $status, string $windowTo): void
    {
        BiometricAttendanceSyncRun::query()->create([
            'source' => 'hikvision_isapi',
            'device_identifier' => 'device.test:80',
            'mode' => 'incremental',
            'window_from' => CarbonImmutable::parse($windowTo)->subHour(),
            'window_to' => $windowTo,
            'status' => $status,
            'attempted_at' => $windowTo,
            'completed_at' => $windowTo,
            'idempotency_key' => fake()->uuid(),
        ]);
    }
}
