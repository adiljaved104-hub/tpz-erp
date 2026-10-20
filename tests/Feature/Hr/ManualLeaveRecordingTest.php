<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Models\AttendanceCorrection;
use App\Models\AttendancePolicy;
use App\Models\BiometricAttendanceEvent;
use App\Models\BiometricEventEmployeeMapping;
use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Hr\AttendanceQueryService;
use App\Services\Hr\HrScopeService;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ManualLeaveRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_record_leave_for_any_active_employee_and_audit_management_provenance(): void
    {
        [$owner, $admin, $team] = $this->managementUsers();
        $otherTeam = Team::query()->create(['name' => 'Other Team', 'status' => true]);
        $ownerTarget = $this->user(EmployeeRole::Staff, $team);
        $adminTarget = $this->user(EmployeeRole::Staff, $otherTeam);
        $this->configureSchedule($ownerTarget->employee, $owner);
        $this->configureSchedule($adminTarget->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $ownerRecord = app(LeaveService::class)->recordOnBehalf($this->requestData($ownerTarget, $annual, '2026-09-07'), $owner);
        $adminRecord = app(LeaveService::class)->recordOnBehalf($this->requestData($adminTarget, $annual, '2026-09-08', 'approved'), $admin);

        $this->assertSame('pending', $ownerRecord->status);
        $this->assertSame('approved', $adminRecord->status);
        $this->assertSame('management', $ownerRecord->recorded_source);
        $this->assertSame($owner->id, $ownerRecord->recorded_by_user_id);
        $this->assertNotNull($ownerRecord->recorded_at);
        $this->assertSame($admin->id, $adminRecord->decided_by_user_id);
        $this->assertNotNull($adminRecord->decided_at);
        $this->assertDatabaseHas('leave_request_events', [
            'leave_request_id' => $adminRecord->id, 'event_type' => 'submitted', 'to_status' => 'pending', 'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('leave_request_events', [
            'leave_request_id' => $adminRecord->id, 'event_type' => 'approved', 'from_status' => 'pending', 'to_status' => 'approved', 'actor_user_id' => $admin->id,
        ]);
        $this->assertSame(2, LeaveRequestEvent::query()->where('leave_request_id', $adminRecord->id)->count());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $adminTarget->id, 'type' => 'leave.approved']);
    }

    public function test_manager_can_record_for_own_team_but_not_another_team_or_self(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $inside = $this->user(EmployeeRole::Staff, $team);
        $otherTeam = Team::query()->create(['name' => 'Separate Team', 'status' => true]);
        $outside = $this->user(EmployeeRole::Staff, $otherTeam);
        $inactive = $this->user(EmployeeRole::Staff, $team);
        $inactive->employee->update(['status' => false]);
        $this->configureSchedule($inside->employee, $owner);
        $this->configureSchedule($outside->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $request = app(LeaveService::class)->recordOnBehalf($this->requestData($inside, $annual, '2026-09-07'), $manager);
        $this->assertSame($inside->employee->id, $request->employee_id);
        $this->assertTrue(app(HrScopeService::class)->canRecordLeaveOnBehalf($manager, $inside->employee));
        $this->assertFalse(app(HrScopeService::class)->canRecordLeaveOnBehalf($manager, $outside->employee));

        try {
            app(LeaveService::class)->recordOnBehalf($this->requestData($outside, $annual, '2026-09-08'), $manager);
            $this->fail('A manager recorded leave outside their team.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(LeaveService::class)->recordOnBehalf($this->requestData($manager, $annual, '2026-09-08'), $manager);
            $this->fail('A manager recorded leave for themselves through the on-behalf workflow.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(LeaveService::class)->recordOnBehalf($this->requestData($inactive, $annual, '2026-09-08'), $manager);
            $this->fail('An inactive employee was available to the manager.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_staff_requires_the_separate_permission_and_an_authorized_employee_scope(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $hrStaff = $this->user(EmployeeRole::Staff, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $otherTeam = Team::query()->create(['name' => 'Unrelated', 'status' => true]);
        $outsider = $this->user(EmployeeRole::Staff, $otherTeam);
        $this->configureSchedule($target->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $this->grant($hrStaff, HrPermission::LeaveRecordOnBehalf, $owner);
        try {
            app(LeaveService::class)->recordOnBehalf($this->requestData($target, $annual, '2026-09-07'), $hrStaff);
            $this->fail('Record permission without employee scope authorized leave creation.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->grant($hrStaff, HrPermission::LeaveViewTeam, $owner);
        $request = app(LeaveService::class)->recordOnBehalf($this->requestData($target, $annual, '2026-09-07'), $hrStaff);
        $this->assertSame('pending', $request->status);
        $this->assertFalse(app(HrScopeService::class)->canRecordLeaveOnBehalf($hrStaff, $outsider->employee));
        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_other_staff_without_record_permission_is_denied_server_side(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $staff = $this->user(EmployeeRole::Staff, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->configureSchedule($target->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $this->expectException(AuthorizationException::class);
        app(LeaveService::class)->recordOnBehalf($this->requestData($target, $annual, '2026-09-07'), $staff);
    }

    public function test_technician_hard_restrictions_continue_to_block_leave_recording(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $technician = $this->user(EmployeeRole::Technician, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->grant($technician, HrPermission::LeaveRecordOnBehalf, $owner);
        $this->grant($technician, HrPermission::LeaveViewTeam, $owner);

        $this->assertFalse(app(HrScopeService::class)->canRecordLeaveOnBehalf($technician, $target->employee));
        $this->expectException(AuthorizationException::class);
        app(LeaveService::class)->recordOnBehalf(
            $this->requestData($target, LeaveType::query()->where('code', 'annual')->firstOrFail(), '2026-09-07'),
            $technician,
        );
    }

    public function test_recording_defaults_to_pending_and_does_not_grant_approval_permission(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->configureSchedule($target->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $service = app(LeaveService::class);

        $pending = $service->recordOnBehalf($this->requestData($target, $annual, '2026-09-07'), $manager);
        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->decided_by_user_id);
        $this->assertFalse(app(HrScopeService::class)->canApproveEmployee($manager, $target->employee));

        try {
            $service->recordOnBehalf($this->requestData($target, $annual, '2026-09-08', 'approved'), $manager);
            $this->fail('Recording permission alone allowed approval.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, LeaveRequest::query()->count());

        $this->grant($manager, HrPermission::LeaveApprove, $owner);
        $approved = $service->recordOnBehalf($this->requestData($target, $annual, '2026-09-08', 'approved'), $manager);
        $this->assertSame('approved', $approved->status);
        $this->assertSame($manager->id, $approved->decided_by_user_id);
    }

    public function test_backdated_annual_leave_uses_existing_balance_accounting(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->configureSchedule($target->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $balanceService = app(LeaveBalanceService::class);

        $pending = app(LeaveService::class)->recordOnBehalf($this->requestData($target, $annual, '2026-09-07'), $manager);
        $this->assertSame('2026-09-07', $pending->from_date->toDateString());
        $this->assertSame('1.00', $pending->requested_working_days);
        $this->assertSame('1.00', $balanceService->for($target->employee, 2026)->pending);
        $this->assertSame('11.00', $balanceService->for($target->employee, 2026)->availableAfterPending);

        $this->grant($manager, HrPermission::LeaveApprove, $owner);
        $approved = app(LeaveService::class)->recordOnBehalf($this->requestData($target, $annual, '2026-09-08', 'approved'), $manager);
        $balance = $balanceService->for($target->employee, 2026);
        $this->assertSame('1.00', $balance->used);
        $this->assertSame('1.00', $balance->pending);
        $this->assertSame('10.00', $balance->availableAfterPending);
        $this->assertSame('management', $approved->recorded_source);
    }

    public function test_unpaid_leave_uses_the_existing_non_deducting_balance_policy(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->configureSchedule($target->employee, $owner);
        $before = app(LeaveBalanceService::class)->for($target->employee, 2026);

        $request = app(LeaveService::class)->recordOnBehalf(
            $this->requestData($target, LeaveType::query()->where('code', 'unpaid')->firstOrFail(), '2026-09-07'),
            $manager,
        );
        $after = app(LeaveBalanceService::class)->for($target->employee, 2026);

        $this->assertNull($request->annual_entitlement_snapshot);
        $this->assertSame('1.00', $request->requested_working_days);
        $this->assertSame($before->availableAfterPending, $after->availableAfterPending);
        $this->assertSame('0.00', $after->pending);
    }

    public function test_existing_comp_off_is_respected_and_pending_comp_off_blocks_a_conflicting_manual_record(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->configureSchedule($target->employee, $owner);
        $approvedCompOff = $this->compOff($target->employee, $owner, 'COF-2026-009871', '2026-09-08', 'approved');
        $pendingCompOff = $this->compOff($target->employee, $owner, 'COF-2026-009872', '2026-09-09', 'pending');
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $request = app(LeaveService::class)->recordOnBehalf($this->requestData($target, $annual, '2026-09-07', 'pending', '2026-09-08'), $manager);
        $this->assertSame('1.00', $request->requested_working_days);
        $this->assertDatabaseHas('leave_request_days', [
            'leave_request_id' => $request->id, 'leave_date' => '2026-09-08 00:00:00',
            'classification' => 'compensatory_off', 'counts_as_leave' => 0, 'compensatory_off_id' => $approvedCompOff->id,
        ]);

        try {
            app(LeaveService::class)->recordOnBehalf($this->requestData($target, $annual, '2026-09-09'), $manager);
            $this->fail('Leave was recorded over a pending Comp Off request.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('pending Comp Off', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_overlapping_pending_or_approved_leave_and_attendance_conflicts_are_rejected_without_mutation(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $schedule = $this->configureSchedule($target->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $service = app(LeaveService::class);
        $service->recordOnBehalf($this->requestData($target, $annual, '2026-09-07', 'pending', '2026-09-08'), $manager);

        try {
            $service->recordOnBehalf($this->requestData($target, $annual, '2026-09-08'), $manager);
            $this->fail('Overlapping leave was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('from_date', $exception->errors());
        }
        $this->assertSame(1, LeaveRequest::query()->count());

        EmployeeAttendance::query()->create([
            'employee_id' => $target->employee->id, 'attendance_date' => '2026-09-09', 'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'first_check_in_at' => '2026-09-09 09:00:00',
            'last_check_out_at' => '2026-09-09 17:00:00', 'worked_minutes' => 480, 'status' => AttendanceStatus::Present,
            'late_minutes' => 0, 'early_departure_minutes' => 0, 'source' => 'biometric', 'is_overridden' => false, 'calculated_at' => now(),
        ]);
        try {
            $service->recordOnBehalf($this->requestData($target, $annual, '2026-09-09'), $manager);
            $this->fail('Leave overwrote or overlapped existing attendance.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Attendance already exists', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(1, LeaveRequest::query()->count());
        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $target->employee->id, 'attendance_date' => '2026-09-09 00:00:00']);
    }

    public function test_emergency_backdated_annual_leave_over_absence_keeps_attendance_pending_then_audits_approved_leave(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $target = $this->user(EmployeeRole::Staff, $team);
        $schedule = $this->configureSchedule($target->employee, $owner);
        $date = '2026-09-07';
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $event = BiometricAttendanceEvent::query()->create([
            'source' => 'hikvision_isapi', 'source_event_id' => 'manual-leave-event-'.fake()->uuid(),
            'external_employee_identifier' => 'BIO-LEAVE-001', 'device_identifier' => 'device.test:80',
            'punched_at' => $date.' 04:05:00', 'punch_type' => 'check_in',
            'raw_metadata' => ['event' => 'preserved test evidence'], 'idempotency_key' => fake()->uuid(),
            'imported_at' => now(), 'created_at' => now(),
        ]);
        $mapping = BiometricEventEmployeeMapping::query()->create([
            'biometric_attendance_event_id' => $event->id, 'employee_id' => $target->employee->id,
            'mapped_by_user_id' => $owner->id, 'reason' => 'Preserve original biometric evidence',
            'mapped_at' => now(), 'active_fingerprint' => 'manual-leave-event:'.$event->id,
            'idempotency_key' => fake()->uuid(), 'created_at' => now(),
        ]);
        $attendance = EmployeeAttendance::query()->create([
            'employee_id' => $target->employee->id, 'attendance_date' => $date, 'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'first_check_in_at' => $date.' 04:05:00',
            'last_check_out_at' => null, 'worked_minutes' => 0, 'status' => AttendanceStatus::Absent,
            'late_minutes' => 0, 'early_departure_minutes' => 0, 'source' => 'biometric',
            'is_overridden' => false, 'calculated_at' => now(),
        ]);
        DB::table('attendance_evidence_links')->insert([
            'employee_attendance_id' => $attendance->id,
            'biometric_attendance_event_id' => $event->id,
            'created_at' => now(),
        ]);
        $eventOriginal = $event->fresh()->getRawOriginal();
        $mappingOriginal = $mapping->fresh()->getRawOriginal();
        $checkIn = $attendance->first_check_in_at?->toDateTimeString();

        $request = app(LeaveService::class)->recordOnBehalf($this->requestData($target, $type, $date), $owner);
        $this->assertSame('pending', $request->status);
        $this->assertSame(AttendanceStatus::Absent, $attendance->fresh()->status, 'Pending leave must not change attendance.');
        $this->assertFalse($attendance->fresh()->is_overridden);
        $this->assertDatabaseCount('attendance_corrections', 0);

        app(LeaveService::class)->approve($request, $owner);

        $updatedAttendance = $attendance->fresh();
        $this->assertSame(AttendanceStatus::ApprovedLeave, $updatedAttendance->status);
        $this->assertTrue($updatedAttendance->is_overridden, 'Later biometric rebuilds must preserve the approved leave status.');
        $this->assertSame($checkIn, $updatedAttendance->first_check_in_at?->toDateTimeString());
        $this->assertNull($updatedAttendance->last_check_out_at);
        $this->assertSame($eventOriginal, $event->fresh()->getRawOriginal());
        $this->assertSame($mappingOriginal, $mapping->fresh()->getRawOriginal());
        $this->assertDatabaseHas('attendance_evidence_links', [
            'employee_attendance_id' => $attendance->id,
            'biometric_attendance_event_id' => $event->id,
        ]);
        $correction = AttendanceCorrection::query()->where('employee_attendance_id', $attendance->id)->sole();
        $this->assertSame($owner->id, $correction->actor_user_id);
        $this->assertSame('absent', $correction->old_values['status']);
        $this->assertSame('approved_leave', $correction->new_values['status']);
        $this->assertStringContainsString($request->reference, $correction->reason);

        $queryService = app(AttendanceQueryService::class);
        $summary = $queryService->summary($queryService->query(
            $owner,
            CarbonImmutable::parse($date),
            CarbonImmutable::parse($date),
            $target->employee->id,
        ));
        $this->assertSame(0, $summary['absent']);
        $this->assertSame(1, $summary['approved_leave']);
    }

    public function test_approved_unpaid_leave_reconciles_absence_as_unpaid_without_consuming_annual_balance(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $target = $this->user(EmployeeRole::Staff, $team);
        $schedule = $this->configureSchedule($target->employee, $owner);
        $date = '2026-09-07';
        $attendance = EmployeeAttendance::query()->create([
            'employee_id' => $target->employee->id, 'attendance_date' => $date, 'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => AttendanceStatus::Absent,
            'late_minutes' => 0, 'early_departure_minutes' => 0, 'source' => 'system_derived',
            'is_overridden' => false, 'calculated_at' => now(),
        ]);
        $before = app(LeaveBalanceService::class)->for($target->employee, 2026);

        $request = app(LeaveService::class)->recordOnBehalf(
            $this->requestData($target, LeaveType::query()->where('code', 'unpaid')->firstOrFail(), $date, 'approved'),
            $owner,
        );

        $after = app(LeaveBalanceService::class)->for($target->employee, 2026);
        $this->assertSame('approved', $request->status);
        $this->assertSame(AttendanceStatus::UnpaidLeave, $attendance->fresh()->status);
        $this->assertSame($before->used, $after->used);
        $this->assertSame($before->availableAfterPending, $after->availableAfterPending);
    }

    public function test_rejected_or_cancelled_pending_leave_never_changes_absent_attendance(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $target = $this->user(EmployeeRole::Staff, $team);
        $schedule = $this->configureSchedule($target->employee, $owner);
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $rejectedDate = '2026-09-07';
        $cancelledDate = '2026-09-08';
        $rejectedAttendance = $this->absentAttendance($target->employee, $schedule, $rejectedDate);
        $cancelledAttendance = $this->absentAttendance($target->employee, $schedule, $cancelledDate);
        $service = app(LeaveService::class);
        $rejected = $service->recordOnBehalf($this->requestData($target, $type, $rejectedDate), $owner);
        $cancelled = $service->recordOnBehalf($this->requestData($target, $type, $cancelledDate), $owner);

        $service->reject($rejected, 'Emergency leave was not confirmed.', $owner);
        $service->cancel($cancelled, $target);

        $this->assertSame(AttendanceStatus::Absent, $rejectedAttendance->fresh()->status);
        $this->assertSame(AttendanceStatus::Absent, $cancelledAttendance->fresh()->status);
        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    public function test_approval_refuses_to_replace_attendance_that_changed_to_present_late_or_half_day(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $target = $this->user(EmployeeRole::Staff, $team);
        $schedule = $this->configureSchedule($target->employee, $owner);
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $service = app(LeaveService::class);

        foreach ([
            '2026-09-07' => AttendanceStatus::Present,
            '2026-09-08' => AttendanceStatus::Late,
            '2026-09-09' => AttendanceStatus::HalfDay,
        ] as $date => $status) {
            $request = $service->recordOnBehalf($this->requestData($target, $type, $date), $owner);
            $attendance = $this->absentAttendance($target->employee, $schedule, $date);
            $attendance->forceFill(['status' => $status])->save();

            try {
                $service->approve($request, $owner);
                $this->fail('Approval replaced a non-Absent attendance status: '.$status->value);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }

            $this->assertSame('pending', $request->fresh()->status);
            $this->assertSame($status, $attendance->fresh()->status);
        }

        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    public function test_approval_rechecks_overlapping_pending_or_approved_leave_records(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->configureSchedule($target->employee, $owner);
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $request = app(LeaveService::class)->recordOnBehalf($this->requestData($target, $type, '2026-09-07'), $owner);

        LeaveRequest::query()->create([
            'reference' => 'LVR-2026-009999', 'employee_id' => $target->employee->id,
            'team_id_at_request' => $team->id, 'leave_type_id' => $type->id,
            'leave_policy_id' => LeavePolicy::query()->value('id'), 'from_date' => '2026-09-07',
            'to_date' => '2026-09-07', 'requested_working_days' => '1.00', 'status' => 'pending',
            'reason' => 'Concurrent conflicting request', 'submitted_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);

        try {
            app(LeaveService::class)->approve($request, $owner);
            $this->fail('An overlapping pending leave request was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('from_date', $exception->errors());
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    private function absentAttendance(Employee $employee, WorkSchedule $schedule, string $date): EmployeeAttendance
    {
        return EmployeeAttendance::query()->create([
            'employee_id' => $employee->id, 'attendance_date' => $date, 'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => AttendanceStatus::Absent,
            'late_minutes' => 0, 'early_departure_minutes' => 0, 'source' => 'system_derived',
            'is_overridden' => false, 'calculated_at' => now(),
        ]);
    }

    public function test_leave_page_exposes_scoped_searchable_management_modal_and_management_badge(): void
    {
        [$owner, , $team] = $this->managementUsers();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $target = $this->user(EmployeeRole::Staff, $team);
        $this->configureSchedule($target->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $component = Livewire::actingAs($manager)->test(LeaveManagement::class)
            ->assertSee('Record Employee Leave')
            ->assertSee('Search by employee name or ID')
            ->assertSee('Approval Status')
            ->assertSee('Pending Approval')
            ->call('openRecordLeave')
            ->set('recordEmployeeSearch', $target->employee->employee_id)
            ->assertSee($target->employee->name)
            ->call('selectRecordEmployee', $target->employee->id)
            ->set('recordLeaveTypeId', $annual->id)
            ->set('recordFromDate', '2026-09-07')
            ->set('recordToDate', '2026-09-07')
            ->assertSee('Calculated Working Days')
            ->assertSet('recordWorkingDays', '1.00')
            ->set('recordReason', 'Emergency leave recorded by management')
            ->call('recordLeave')
            ->assertHasNoErrors();

        $request = LeaveRequest::query()->where('employee_id', $target->employee->id)->firstOrFail();
        $component->assertSee('Recorded by Management');
        $this->assertSame('pending', $request->status);
        $this->assertSame($manager->id, $request->recorded_by_user_id);
        $this->assertSame(1, LeaveRequestEvent::query()->where('leave_request_id', $request->id)->count());
    }

    public function test_record_on_behalf_permission_is_a_separate_access_control_action(): void
    {
        $module = app(AccessControlModuleRegistry::class)->keyed()['leave'];
        $catalog = collect(app(EmployeePermissionCatalog::class)->groups())->flatten(1)->keyBy('key');

        $this->assertContains(HrPermission::LeaveRecordOnBehalf->value, $module['edit_keys']);
        $this->assertSame('Record Leave on Behalf', $catalog[HrPermission::LeaveRecordOnBehalf->value]['label']);
        $this->assertSame(['missing' => [], 'unknown' => [], 'duplicates' => []], app(AccessControlModuleRegistry::class)->reconcile());
    }

    public function test_legacy_leave_rows_receive_employee_source_without_losing_existing_fields(): void
    {
        [, , $team] = $this->managementUsers();
        $employee = $this->user(EmployeeRole::Staff, $team);
        $type = LeaveType::query()->where('code', 'unpaid')->firstOrFail();
        $policyId = LeavePolicy::query()->value('id');
        $legacy = LeaveRequest::query()->create([
            'reference' => 'LVR-2026-008811', 'employee_id' => $employee->employee->id, 'team_id_at_request' => $team->id,
            'leave_type_id' => $type->id, 'leave_policy_id' => $policyId, 'from_date' => '2026-09-07', 'to_date' => '2026-09-07',
            'requested_working_days' => '1.00', 'status' => 'pending', 'reason' => 'Existing employee-submitted history',
            'submitted_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);

        $this->assertSame('employee', $legacy->fresh()->recorded_source);
        $this->assertNull($legacy->recorded_by_user_id);
        $this->assertNull($legacy->recorded_at);
        $this->assertSame('Existing employee-submitted history', $legacy->fresh()->reason);
    }

    private function requestData(User $target, LeaveType $type, string $from, string $status = 'pending', ?string $to = null): array
    {
        return [
            'employee_id' => $target->employee->id,
            'leave_type_id' => $type->id,
            'from_date' => $from,
            'to_date' => $to ?? $from,
            'reason' => 'Recorded by management for an employee circumstance.',
            'approval_status' => $status,
        ];
    }

    private function managementUsers(): array
    {
        $team = Team::query()->create(['name' => 'Management', 'status' => true]);

        return [$this->user(EmployeeRole::Owner, $team), $this->user(EmployeeRole::Admin, $team), $team];
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team->id, 'status' => true,
        ]);

        return $user->refresh();
    }

    private function configureSchedule(Employee $employee, User $actor): WorkSchedule
    {
        $schedule = WorkSchedule::query()->create([
            'code' => 'MANUAL_LEAVE_'.strtoupper(fake()->unique()->lexify('??????')),
            'name' => 'Manual Leave Test Schedule', 'timezone' => 'Asia/Karachi', 'schedule_type' => 'standard',
            'cycle_length_weeks' => 1, 'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => true,
        ]);
        foreach (range(0, 6) as $weekday) {
            $working = ! in_array($weekday, [0, 6], true);
            WorkScheduleDay::query()->create([
                'work_schedule_id' => $schedule->id, 'cycle_week' => 1, 'weekday' => $weekday,
                'is_working_day' => $working, 'expected_start_time' => $working ? '09:00' : null,
                'expected_end_time' => $working ? '17:00' : null, 'earns_compensatory_off' => false,
            ]);
        }
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id, 'employee_id' => $employee->id,
            'effective_from' => '2026-01-01', 'assigned_by_user_id' => $actor->id, 'reason' => 'Manual leave test schedule',
        ]);

        return $schedule;
    }

    private function compOff(Employee $employee, User $actor, string $reference, string $offDate, string $status): CompensatoryOff
    {
        return CompensatoryOff::query()->create([
            'reference' => $reference, 'employee_id' => $employee->id, 'earned_work_date' => null,
            'off_date' => $offDate, 'source' => 'manual', 'status' => $status, 'granted_by_user_id' => $actor->id,
            'approved_by_user_id' => $status === 'approved' ? $actor->id : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'reason' => 'Existing Comp Off for leave validation', 'idempotency_key' => fake()->uuid(),
        ]);
    }

    private function grant(User $user, HrPermission $permission, User $actor): void
    {
        EmployeePermissionOverride::query()->updateOrCreate(
            ['employee_id' => $user->employee->id, 'permission_key' => $permission->value],
            ['effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $actor->id, 'reason' => 'Manual leave recording test'],
        );
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }
}
