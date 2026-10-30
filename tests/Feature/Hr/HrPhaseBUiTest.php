<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\Attendance;
use App\Filament\Pages\Hr\BiometricSync;
use App\Filament\Pages\Hr\CompOff;
use App\Filament\Pages\Hr\WorkSchedules;
use App\Models\AttendanceCorrection;
use App\Models\BiometricAttendanceEvent;
use App\Models\BiometricEventEmployeeMapping;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePermissionOverride;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\BiometricAttendanceProcessor;
use App\Services\Hr\EffectiveWorkScheduleResolver;
use App\Services\Hr\WorkScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HrPhaseBUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_schedule_dashboard_supports_atomic_bulk_employee_assignment_and_grace_overrides(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $first = $this->user(EmployeeRole::Staff, $team);
        $second = $this->user(EmployeeRole::Staff, $team);
        $schedule = WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail();
        $this->ensurePattern($schedule, $owner);

        Livewire::actingAs($owner)->test(WorkSchedules::class)
            ->assertSee('Active schedules')
            ->assertSee('Employees without a schedule')
            ->assertSee('Grace period (minutes)')
            ->assertSee('Pakistan Office — Standard 9 AM to 5 PM')
            ->assertSee('1 = same schedule every week. 2 = alternating two-week schedule. 3 = repeating three-week schedule.')
            ->assertSee('Select visible')
            ->set('assignmentScheduleId', $schedule->id)
            ->set('assignmentTargetType', 'employee')
            ->set('assignmentEmployeeIds', [$first->employee->id, $second->employee->id])
            ->set('assignmentEffectiveFrom', '2026-10-20')
            ->set('assignmentGraceMinutesOverride', 15)
            ->set('assignmentReason', 'Special late-arrival grace for the support shift')
            ->call('assignSchedule')
            ->assertHasNoErrors();

        $this->assertSame(2, WorkScheduleAssignment::query()->whereIn('employee_id', [$first->employee->id, $second->employee->id])->count());
        foreach ([$first, $second] as $employeeUser) {
            $assignment = WorkScheduleAssignment::query()->where('employee_id', $employeeUser->employee->id)->sole();
            $this->assertSame(15, $assignment->grace_minutes_override);
            $this->assertSame(15, app(EffectiveWorkScheduleResolver::class)->graceMinutes($assignment->load('schedule.attendancePolicy')));
        }
        $this->assertSame(5, $schedule->fresh()->grace_minutes);
    }

    public function test_attendance_calendar_renders_a_seven_column_month_and_never_fabricates_missing_attendance(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);

        Livewire::actingAs($owner)->test(Attendance::class)
            ->assertSee('grid grid-cols-7 gap-1 text-center', false)
            ->assertSee('grid grid-cols-7 gap-1 sm:gap-1.5', false)
            ->assertSee('Mon')->assertSee('Tue')->assertSee('Wed')->assertSee('Thu')->assertSee('Fri')->assertSee('Sat')->assertSee('Sun')
            ->assertSee('No attendance records in this month. Missing records are not silently fabricated.')
            ->set('calendarMonth', '2026-10')
            ->assertViewHas('calendarDays', fn (array $days): bool => count($days) === 42 && $days[0]['date']->isMonday() && $days[41]['date']->isSunday())
            ->assertViewHas('calendarRecords', fn ($records): bool => $records->isEmpty())
            ->assertSee('October 2026')
            ->call('previousCalendarMonth')
            ->assertSet('calendarMonth', '2026-09')
            ->assertSee('September 2026')
            ->call('nextCalendarMonth')
            ->assertSet('calendarMonth', '2026-10')
            ->assertSee('Next month');
    }

    public function test_owner_can_record_missing_attendance_once_and_later_biometric_sync_cannot_overwrite_it(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $employee = $this->user(EmployeeRole::Staff, $team);
        $this->ensurePattern(WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail(), $owner);
        $this->assign($employee->employee, $owner, 15);

        Livewire::actingAs($owner)->test(Attendance::class)
            ->assertSee('Attendance calendar')
            ->assertSee('Record Manual Attendance')
            ->assertSee('Search employees')
            ->assertSee('Example: Biometric device was not working, attendance verified by management.')
            ->call('openManualAttendance')
            ->set('manualEmployeeId', $employee->employee->id)
            ->set('manualAttendanceDate', '2026-10-20')
            ->set('manualStatus', 'present')
            ->set('manualFirstCheckIn', '09:10')
            ->set('manualLastCheckOut', '17:00')
            ->set('manualReason', 'Biometric device was offline; supervisor confirmed the log')
            ->call('saveManualAttendance')
            ->assertHasNoErrors();

        $attendance = EmployeeAttendance::query()->sole();
        $this->assertSame('manual', $attendance->source);
        $this->assertTrue($attendance->is_overridden);
        $this->assertSame(15, $attendance->grace_minutes);
        $this->assertSame('present', $attendance->status->value);
        $this->assertSame('09:10', $attendance->first_check_in_at->timezone('Asia/Karachi')->format('H:i'));
        $audit = AttendanceCorrection::query()->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame([], $audit->old_values);
        $this->assertSame('present', $audit->new_values['status']);
        $this->assertStringContainsString('device was offline', $audit->reason);

        $event = $this->mappedEvent($employee->employee, $owner, 'BIO-MANUAL', '2026-10-20 04:30:00');
        $eventBefore = collect(['source_event_id', 'punched_at', 'raw_metadata', 'idempotency_key'])
            ->mapWithKeys(fn (string $attribute): array => [$attribute => $event->getRawOriginal($attribute)])
            ->all();
        app(BiometricAttendanceProcessor::class)->rebuild($employee->employee, CarbonImmutable::parse('2026-10-20', 'Asia/Karachi'));

        $attendance->refresh();
        $this->assertSame('09:10', $attendance->first_check_in_at->timezone('Asia/Karachi')->format('H:i'));
        $this->assertSame('17:00', $attendance->last_check_out_at->timezone('Asia/Karachi')->format('H:i'));
        $eventAfter = $event->fresh();
        $this->assertSame(
            $eventBefore,
            collect(array_keys($eventBefore))
                ->mapWithKeys(fn (string $attribute): array => [$attribute => $eventAfter->getRawOriginal($attribute)])
                ->all(),
        );
        $this->assertDatabaseHas('attendance_evidence_links', ['employee_attendance_id' => $attendance->id, 'biometric_attendance_event_id' => $event->id]);

        try {
            app(AttendanceCorrectionService::class)->createManual([
                'employee_id' => $employee->employee->id,
                'attendance_date' => '2026-10-20',
                'status' => 'present',
                'reason' => 'Duplicate attempt',
            ], $owner);
            $this->fail('A second manual record for the same Employee/date was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attendance_date', $exception->errors());
        }
        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertDatabaseCount('attendance_corrections', 1);

        try {
            app(AttendanceCorrectionService::class)->createManual([
                'employee_id' => $employee->employee->id,
                'attendance_date' => '2026-10-21',
                'status' => 'present',
                'reason' => '   ',
            ], $owner);
            $this->fail('A manual record with a whitespace-only reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertDatabaseCount('attendance_corrections', 1);
    }

    public function test_employee_grace_threshold_is_independent_of_shift_start_and_is_snapshotted(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $employee = $this->user(EmployeeRole::Staff, $team);
        $this->assign($employee->employee, $owner, 15);

        $withinGrace = app(AttendanceCorrectionService::class)->createManual([
            'employee_id' => $employee->employee->id,
            'attendance_date' => '2026-10-20',
            'status' => 'present',
            'first_check_in_at' => '2026-10-20 09:15:00',
            'reason' => 'Verified attendance within grace',
        ], $owner);
        $this->assertSame('present', $withinGrace->status->value);
        $this->assertSame(0, $withinGrace->late_minutes);
        $this->assertSame(15, $withinGrace->grace_minutes);

        $lateEmployee = $this->user(EmployeeRole::Staff, $team);
        $this->assign($lateEmployee->employee, $owner, 15);
        $late = app(AttendanceCorrectionService::class)->createManual([
            'employee_id' => $lateEmployee->employee->id,
            'attendance_date' => '2026-10-20',
            'status' => 'present',
            'first_check_in_at' => '2026-10-20 09:16:00',
            'reason' => 'Verified arrival beyond the fifteen minute grace period',
        ], $owner);
        $this->assertSame('late', $late->status->value);
        $this->assertSame(16, $late->late_minutes);
        $this->assertSame(15, $late->grace_minutes);
    }

    public function test_specific_attendance_date_overrides_the_default_today_period(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $employee = $this->user(EmployeeRole::Staff, $team);
        $this->ensurePattern(WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail(), $owner);
        $this->assign($employee->employee, $owner, 5);

        app(AttendanceCorrectionService::class)->createManual([
            'employee_id' => $employee->employee->id,
            'attendance_date' => '2026-08-21',
            'status' => 'present',
            'reason' => 'Verified historical attendance date search',
        ], $owner);

        Livewire::actingAs($owner)->test(Attendance::class)
            ->set('period', 'today')
            ->set('dateFilter', '2026-08-21')
            ->assertSee('21 Aug 2026')
            ->assertSee($employee->employee->employee_id);
    }

    public function test_biometric_attendance_uses_effective_employee_grace_for_late_classification(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $this->ensurePattern(WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail(), $owner);
        $standardGraceEmployee = $this->user(EmployeeRole::Staff, $team);
        $specialGraceEmployee = $this->user(EmployeeRole::Staff, $team);
        $this->assign($standardGraceEmployee->employee, $owner, 5);
        $this->assign($specialGraceEmployee->employee, $owner, 15);

        $standardEvent = $this->mappedEvent($standardGraceEmployee->employee, $owner, 'BIO-STANDARD-GRACE', '2026-10-20 04:06:00');
        $specialEvent = $this->mappedEvent($specialGraceEmployee->employee, $owner, 'BIO-SPECIAL-GRACE', '2026-10-20 04:15:00');
        $date = CarbonImmutable::parse('2026-10-20', 'Asia/Karachi');
        app(BiometricAttendanceProcessor::class)->rebuild($standardGraceEmployee->employee, $date);
        app(BiometricAttendanceProcessor::class)->rebuild($specialGraceEmployee->employee, $date);

        $standardAttendance = EmployeeAttendance::query()->where('employee_id', $standardGraceEmployee->employee->id)->sole();
        $specialAttendance = EmployeeAttendance::query()->where('employee_id', $specialGraceEmployee->employee->id)->sole();
        $this->assertSame('late', $standardAttendance->status->value);
        $this->assertSame(6, $standardAttendance->late_minutes);
        $this->assertSame(5, $standardAttendance->grace_minutes);
        $this->assertSame('present', $specialAttendance->status->value);
        $this->assertSame(0, $specialAttendance->late_minutes);
        $this->assertSame(15, $specialAttendance->grace_minutes);
        $this->assertDatabaseHas('attendance_evidence_links', ['biometric_attendance_event_id' => $standardEvent->id]);
        $this->assertDatabaseHas('attendance_evidence_links', ['biometric_attendance_event_id' => $specialEvent->id]);
    }

    public function test_manager_with_correction_permission_can_correct_existing_rows_but_cannot_create_missing_attendance(): void
    {
        $teamA = Team::query()->create(['name' => 'A', 'status' => true]);
        $teamB = Team::query()->create(['name' => 'B', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $teamA);
        $manager = $this->user(EmployeeRole::Manager, $teamA);
        $inside = $this->user(EmployeeRole::Staff, $teamA);
        $outside = $this->user(EmployeeRole::Staff, $teamB);
        $this->grant($manager, HrPermission::AttendanceCorrect, $owner);
        $this->ensurePattern(WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail(), $owner);
        $this->assign($inside->employee, $owner, 5);
        $this->assign($outside->employee, $owner, 5);

        $created = app(AttendanceCorrectionService::class)->createManual([
            'employee_id' => $inside->employee->id,
            'attendance_date' => '2026-10-20',
            'status' => 'present',
            'reason' => 'Owner recorded the missing attendance',
        ], $owner);
        $this->assertSame($inside->employee->id, $created->employee_id);

        app(AttendanceCorrectionService::class)->correct($created, [
            'status' => 'late',
            'first_check_in_at' => null,
            'last_check_out_at' => null,
            'late_minutes' => 6,
            'early_departure_minutes' => 0,
        ], 'Manager corrected a verified existing attendance row', $manager);
        $this->assertSame('late', $created->fresh()->status->value);

        try {
            app(AttendanceCorrectionService::class)->createManual([
                'employee_id' => $inside->employee->id,
                'attendance_date' => '2026-10-21',
                'status' => 'present',
                'reason' => 'Manager attempt to create missing attendance',
            ], $manager);
            $this->fail('A manager created a missing attendance record with only AttendanceCorrect.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $ownerCreatedOutsideTeam = app(AttendanceCorrectionService::class)->createManual([
            'employee_id' => $outside->employee->id,
            'attendance_date' => '2026-10-21',
            'status' => 'present',
            'reason' => 'Owner verified an out-of-team missing attendance',
        ], $owner);
        $this->assertSame($outside->employee->id, $ownerCreatedOutsideTeam->employee_id);
        $this->assertDatabaseCount('employee_attendances', 2);
        $this->assertDatabaseCount('attendance_corrections', 3);
    }

    public function test_admin_can_create_missing_attendance_but_staff_with_correction_override_cannot(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $admin = $this->user(EmployeeRole::Admin, $team);
        $manager = $this->user(EmployeeRole::Manager, $team);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $this->grant($manager, HrPermission::AttendanceCorrect, $owner);
        $this->grant($staff, HrPermission::AttendanceCorrect, $owner);
        $employee = $this->user(EmployeeRole::Staff, $team);
        $this->ensurePattern(WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail(), $owner);
        $this->assign($employee->employee, $owner, 5);

        $created = app(AttendanceCorrectionService::class)->createManual([
            'employee_id' => $employee->employee->id,
            'attendance_date' => '2026-10-20',
            'status' => 'present',
            'reason' => 'Admin verified the missing attendance',
        ], $admin);
        $this->assertSame($employee->employee->id, $created->employee_id);
        $this->assertDatabaseCount('attendance_corrections', 1);

        foreach ([$manager, $staff] as $unauthorizedCreator) {
            try {
                app(AttendanceCorrectionService::class)->createManual([
                    'employee_id' => $employee->employee->id,
                    'attendance_date' => '2026-10-21',
                    'status' => 'present',
                    'reason' => 'Attempt with AttendanceCorrect only',
                ], $unauthorizedCreator);
                $this->fail('A non-Owner/Admin role created a missing attendance record.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $managerAttendancePage = Livewire::actingAs($manager)->test(Attendance::class);
        $managerAttendancePage->assertDontSee('Record Manual Attendance');
        $managerAttendancePage->call('openManualAttendance')->assertForbidden();
        Livewire::actingAs($manager)->test(Attendance::class)->call('saveManualAttendance')->assertForbidden();
        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertDatabaseCount('attendance_corrections', 1);
    }

    public function test_comp_off_and_biometric_pages_expose_status_summaries_and_keep_actions_explicit(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $employee = $this->user(EmployeeRole::Staff, $team);
        $this->unmappedEvent('BIO-UNMAPPED');
        $mapped = $this->unmappedEvent('BIO-MAPPED');
        BiometricEventEmployeeMapping::query()->create([
            'biometric_attendance_event_id' => $mapped->id,
            'employee_id' => $employee->employee->id,
            'mapped_by_user_id' => $owner->id,
            'reason' => 'Verified mapping for queue test',
            'mapped_at' => now(),
            'active_fingerprint' => 'biometric-event:'.$mapped->id,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        Livewire::actingAs($owner)->test(CompOff::class)
            ->assertSee('Earned credits')->assertSee('Reserved credits')->assertSee('Available credits')
            ->assertSee('Request Comp-Off')->assertSee('Authorize Sunday duty')
            ->assertSee('Sunday date')->assertSee('Manager decisions')
            ->assertViewHas('teamEmployees', fn ($options): bool => $options->count() === 1 && $options->first()->id === $employee->employee->id);
        Livewire::actingAs($owner)->test(BiometricSync::class)
            ->assertSee('Read-only monitoring')
            ->assertSee('Synchronization and recovery actions')
            ->assertSee('1 unmapped identifiers')
            ->assertSee('1 unprocessed mapped events')
            ->assertSee('No failed or partial synchronization attempts were recorded.')
            ->assertSee('Sync Now');
    }

    public function test_intentionally_disabled_biometric_integration_has_a_clear_neutral_status_and_sends_no_request(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        config()->set('hikvision.enabled', false);
        Http::fake();

        Livewire::actingAs($owner)->test(BiometricSync::class)
            ->assertSee('Biometric Integration Disabled')
            ->assertSee('Hikvision synchronization is not enabled in this environment.')
            ->call('testConnection')
            ->assertSet('connectionResult.status', 'Disabled')
            ->assertSee('Disabled')
            ->assertDontSee('Unexpected Response');

        Http::assertNothingSent();
    }

    private function assign(Employee $employee, User $actor, int $grace): WorkScheduleAssignment
    {
        $schedule = WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail();
        $this->ensurePattern($schedule, $actor);

        return WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id,
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'grace_minutes_override' => $grace,
            'assigned_by_user_id' => $actor->id,
            'reason' => 'Phase B UI test assignment',
        ]);
    }

    private function ensurePattern(WorkSchedule $schedule, User $actor): void
    {
        if ($schedule->days()->exists()) {
            return;
        }
        $days = [];
        foreach (range(0, 6) as $weekday) {
            $working = ! in_array($weekday, [0, 6], true);
            $days[] = [
                'cycle_week' => 1,
                'weekday' => $weekday,
                'is_working_day' => $working,
                'expected_start_time' => $working ? '09:00' : null,
                'expected_end_time' => $working ? '17:00' : null,
                'break_start_time' => null,
                'break_end_time' => null,
                'earns_compensatory_off' => false,
            ];
        }
        app(WorkScheduleService::class)->configurePattern($schedule, $days, $actor);
    }

    private function mappedEvent(Employee $employee, User $actor, string $external, string $punchedAt): BiometricAttendanceEvent
    {
        $event = $this->unmappedEvent($external, $punchedAt);
        BiometricEventEmployeeMapping::query()->create([
            'biometric_attendance_event_id' => $event->id,
            'employee_id' => $employee->id,
            'mapped_by_user_id' => $actor->id,
            'reason' => 'Verified attendance mapping',
            'mapped_at' => now(),
            'active_fingerprint' => 'biometric-event:'.$event->id,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return $event;
    }

    private function unmappedEvent(string $external, string $punchedAt = '2026-10-20 04:00:00'): BiometricAttendanceEvent
    {
        return BiometricAttendanceEvent::query()->create([
            'source' => 'hikvision_isapi',
            'source_event_id' => 'phase-b-'.Str::uuid(),
            'external_employee_identifier' => $external,
            'device_identifier' => 'device.test:80',
            'punched_at' => $punchedAt,
            'punch_type' => 'check_in',
            'raw_metadata' => ['employee_name' => 'Test person'],
            'idempotency_key' => (string) Str::uuid(),
            'imported_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function grant(User $user, HrPermission $permission, User $actor): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $user->employee->id,
            'permission_key' => $permission->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $actor->id,
            'reason' => 'Explicit attendance correction test authorization',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'team_id' => $team->id,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
