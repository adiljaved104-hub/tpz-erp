<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\CompOff;
use App\Filament\Pages\Hr\PublicHolidays;
use App\Filament\Pages\Hr\WorkSchedules;
use App\Models\AttendancePolicy;
use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\AttendanceInterpretationService;
use App\Services\Hr\AttendanceDayContextFactory;
use App\Services\Hr\CompensatoryOffService;
use App\Services\Hr\EffectiveWorkScheduleResolver;
use App\Services\Hr\LeaveDayCalculator;
use App\Services\Hr\PublicHolidayService;
use App\Services\Hr\WorkScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HrPhaseB2Test extends TestCase
{
    use RefreshDatabase;

    public function test_employee_assignment_overrides_team_and_effective_history_is_preserved(): void
    {
        [$owner, $staff, , $team] = $this->people();
        $standard = $this->schedule('STANDARD_A', 1);
        $alternative = $this->schedule('ALT_A', 2, true);
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $standard->id, 'team_id' => $team->id, 'effective_from' => '2026-01-01',
            'assigned_by_user_id' => $owner->id, 'reason' => 'Team standard schedule',
        ]);
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $alternative->id, 'employee_id' => $staff->employee->id,
            'effective_from' => '2026-08-03', 'effective_to' => '2026-12-31',
            'assigned_by_user_id' => $owner->id, 'reason' => 'Employee alternative schedule',
        ]);

        $resolver = app(EffectiveWorkScheduleResolver::class);
        $this->assertSame($standard->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-06-30'))->work_schedule_id);
        $this->assertSame($alternative->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-08-09'))->work_schedule_id);
        $this->assertSame($standard->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2027-01-01'))->work_schedule_id);
        $this->assertTrue($resolver->day($staff->employee, CarbonImmutable::parse('2026-08-09'))['day']->is_working_day);
        $this->assertFalse($resolver->day($staff->employee, CarbonImmutable::parse('2026-08-15'))['day']->is_working_day);
        $this->assertFalse($resolver->day($staff->employee, CarbonImmutable::parse('2026-08-16'))['day']->is_working_day);
    }

    public function test_team_assignment_applies_when_no_employee_assignment_and_no_schedule_is_guessed(): void
    {
        [$owner, $staff, , $team] = $this->people();
        $schedule = $this->schedule('TEAM_ONLY', 1);
        app(WorkScheduleService::class)->assign([
            'work_schedule_id' => $schedule->id, 'team_id' => $team->id,
            'effective_from' => '2026-08-01', 'reason' => 'Team operating schedule',
        ], $owner);

        $resolver = app(EffectiveWorkScheduleResolver::class);
        $this->assertSame($schedule->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-08-10'))->work_schedule_id);
        $unassigned = $this->user(EmployeeRole::Staff);
        $this->assertNull($resolver->assignment($unassigned->employee, CarbonImmutable::parse('2026-08-10')));
        $this->expectException(ValidationException::class);
        $resolver->day($unassigned->employee, CarbonImmutable::parse('2026-08-10'));
    }

    public function test_assigned_empty_schedule_allows_one_time_pattern_initialization_then_becomes_immutable(): void
    {
        [$owner, $staff] = $this->people();
        $schedule = WorkSchedule::query()->create([
            'code' => 'INITIALIZE_ONCE', 'name' => 'Initialize Once', 'timezone' => 'Asia/Karachi',
            'schedule_type' => 'standard', 'cycle_length_weeks' => 1,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => true,
        ]);
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id, 'employee_id' => $staff->employee->id,
            'effective_from' => '2026-08-01', 'effective_to' => '2028-09-01',
            'assigned_by_user_id' => $owner->id, 'reason' => 'Legacy assigned schedule awaiting its initial pattern',
        ]);

        $initialized = app(WorkScheduleService::class)->configurePattern($schedule, $this->pattern(1), $owner);

        $this->assertCount(7, $initialized->days);
        $this->expectException(ValidationException::class);
        app(WorkScheduleService::class)->configurePattern($initialized, $this->pattern(1), $owner);
    }

    public function test_incomplete_schedule_cannot_receive_new_assignment_and_complete_schedule_resolves_boundary_and_off_day(): void
    {
        [$owner, $staff] = $this->people();
        $incomplete = WorkSchedule::query()->create([
            'code' => 'INCOMPLETE', 'name' => 'Incomplete', 'timezone' => 'Asia/Karachi',
            'schedule_type' => 'standard', 'cycle_length_weeks' => 1,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => true,
        ]);
        try {
            app(WorkScheduleService::class)->assign([
                'work_schedule_id' => $incomplete->id, 'employee_id' => $staff->employee->id,
                'effective_from' => '2026-08-01', 'reason' => 'Must be blocked without a pattern',
            ], $owner);
            $this->fail('An incomplete Work Schedule was assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_schedule_id', $exception->errors());
        }

        $complete = $this->schedule('BOUNDARY_OFF', 1);
        app(WorkScheduleService::class)->assign([
            'work_schedule_id' => $complete->id, 'employee_id' => $staff->employee->id,
            'effective_from' => '2026-08-01', 'effective_to' => '2028-09-01', 'reason' => 'Boundary resolution test',
        ], $owner);
        $resolver = app(EffectiveWorkScheduleResolver::class);
        $this->assertSame($complete->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-08-01'))->work_schedule_id);
        $this->assertSame($complete->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-08-21'))->work_schedule_id);
        $this->assertFalse($resolver->day($staff->employee, CarbonImmutable::parse('2026-08-01'))['day']->is_working_day);
        $this->assertTrue($resolver->day($staff->employee, CarbonImmutable::parse('2026-08-03'))['day']->is_working_day);
    }

    public function test_scheduled_off_holiday_and_approved_comp_off_are_excluded_from_annual_leave(): void
    {
        [$owner, $staff] = $this->people();
        $schedule = $this->schedule('LEAVE_CAL', 1);
        $this->assignEmployee($schedule, $staff->employee, $owner);
        $holiday = PublicHoliday::query()->create([
            'name' => 'Configured Holiday', 'holiday_date' => '2026-09-08', 'status' => true, 'created_by_user_id' => $owner->id,
        ]);
        $compOff = CompensatoryOff::query()->create([
            'reference' => 'COF-2026-009999', 'employee_id' => $staff->employee->id,
            'earned_work_date' => '2026-08-30', 'off_date' => '2026-09-09', 'source' => 'scheduled_sunday',
            'status' => 'approved', 'granted_by_user_id' => $owner->id, 'approved_by_user_id' => $owner->id,
            'approved_at' => now(), 'reason' => 'Approved earned day', 'idempotency_key' => fake()->uuid(),
        ]);

        $result = app(LeaveDayCalculator::class)->calculate($staff->employee, CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-13'));

        $this->assertSame('3.00', $result['working_days']);
        $byDate = collect($result['days'])->keyBy('leave_date');
        $this->assertSame('public_holiday', $byDate['2026-09-08']['classification']);
        $this->assertSame($holiday->id, $byDate['2026-09-08']['public_holiday_id']);
        $this->assertSame('compensatory_off', $byDate['2026-09-09']['classification']);
        $this->assertSame($compOff->id, $byDate['2026-09-09']['compensatory_off_id']);
        $this->assertSame('schedule_off', $byDate['2026-09-12']['classification']);
        $this->assertFalse($byDate['2026-09-09']['counts_as_leave']);
    }

    public function test_public_holiday_and_comp_off_do_not_become_absent_and_punch_evidence_is_preserved(): void
    {
        [$owner, $staff] = $this->people();
        $schedule = $this->schedule('ATT_CTX', 1);
        $this->assignEmployee($schedule, $staff->employee, $owner);
        PublicHoliday::query()->create([
            'name' => 'Configured Holiday', 'holiday_date' => '2026-09-07', 'status' => true, 'created_by_user_id' => $owner->id,
        ]);
        CompensatoryOff::query()->create([
            'reference' => 'COF-2026-009998', 'employee_id' => $staff->employee->id,
            'earned_work_date' => '2026-08-30', 'off_date' => '2026-09-09', 'source' => 'scheduled_sunday',
            'status' => 'approved', 'granted_by_user_id' => $owner->id, 'approved_by_user_id' => $owner->id,
            'approved_at' => now(), 'reason' => 'Approved earned day', 'idempotency_key' => fake()->uuid(),
        ]);
        $factory = app(AttendanceDayContextFactory::class);
        $interpreter = app(AttendanceInterpretationService::class);
        $holidayContext = $factory->make($staff->employee, CarbonImmutable::parse('2026-09-07'), true, 480);

        $this->assertTrue($holidayContext->hasQualifyingPunch);
        $this->assertSame(AttendanceStatus::PublicHoliday, $interpreter->interpret($holidayContext));
        $this->assertSame(AttendanceStatus::CompensatoryOff, $interpreter->interpret($factory->make($staff->employee, CarbonImmutable::parse('2026-09-09'), false)));
        $this->assertSame(AttendanceStatus::WeekendOff, $interpreter->interpret($factory->make($staff->employee, CarbonImmutable::parse('2026-09-12'), false)));
    }

    public function test_qualifying_sunday_evidence_earns_one_approval_required_comp_off(): void
    {
        [$owner, $staff] = $this->people();
        $schedule = $this->schedule('ALT_EARN', 2, true);
        $this->assignEmployee($schedule, $staff->employee, $owner, '2026-08-03');
        $this->attendance($staff->employee, $schedule, '2026-08-09');

        $record = app(CompensatoryOffService::class)->requestFromSundayDuty([
            'employee_id' => $staff->employee->id, 'earned_work_date' => '2026-08-09',
            'off_date' => '2026-08-15', 'reason' => 'Worked scheduled Sunday duty',
        ], $owner);

        $this->assertSame('pending', $record->status);
        $this->assertSame('scheduled_sunday', $record->source);
        $this->assertNull($record->approved_by_user_id);
        $this->assertDatabaseCount('compensatory_offs', 1);

        try {
            app(CompensatoryOffService::class)->requestFromSundayDuty([
                'employee_id' => $staff->employee->id, 'earned_work_date' => '2026-08-09',
                'off_date' => '2026-08-16', 'reason' => 'Duplicate attempt',
            ], $owner);
            $this->fail('Duplicate Sunday earning was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('compensatory_offs', 1);
    }

    public function test_sunday_calendar_date_without_qualifying_attendance_earns_nothing(): void
    {
        [$owner, $staff] = $this->people();
        $schedule = $this->schedule('ALT_NO_EVIDENCE', 2, true);
        $this->assignEmployee($schedule, $staff->employee, $owner, '2026-08-03');

        try {
            app(CompensatoryOffService::class)->requestFromSundayDuty([
                'employee_id' => $staff->employee->id, 'earned_work_date' => '2026-08-09',
                'off_date' => '2026-08-15', 'reason' => 'No evidence',
            ], $owner);
            $this->fail('Comp Off was fabricated without evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('earned_work_date', $exception->errors());
        }
        $this->assertDatabaseCount('compensatory_offs', 0);
    }

    public function test_comp_off_cannot_overlap_existing_chargeable_leave(): void
    {
        [$owner, $staff] = $this->people();
        $schedule = $this->schedule('ALT_CONFLICT', 2, true);
        $this->assignEmployee($schedule, $staff->employee, $owner, '2026-08-03');
        $this->attendance($staff->employee, $schedule, '2026-08-09');
        $leave = LeaveRequest::query()->create([
            'reference' => 'LVR-2026-009999', 'employee_id' => $staff->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'leave_policy_id' => LeavePolicy::query()->value('id'),
            'from_date' => '2026-08-17', 'to_date' => '2026-08-17', 'requested_working_days' => '1.00',
            'status' => 'approved', 'reason' => 'Existing Annual Leave', 'submitted_at' => now(),
            'decided_by_user_id' => $owner->id, 'decided_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);
        LeaveRequestDay::query()->create([
            'leave_request_id' => $leave->id, 'leave_date' => '2026-08-17', 'work_schedule_id' => $schedule->id,
            'classification' => 'working_leave', 'counts_as_leave' => true, 'leave_units' => '1.00',
        ]);

        try {
            app(CompensatoryOffService::class)->requestFromSundayDuty([
                'employee_id' => $staff->employee->id, 'earned_work_date' => '2026-08-09',
                'off_date' => '2026-08-17', 'reason' => 'Conflicting Comp Off',
            ], $owner);
            $this->fail('Comp Off overlapped chargeable Annual Leave.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('off_date', $exception->errors());
        }
        $this->assertDatabaseCount('compensatory_offs', 0);
    }

    public function test_employee_cannot_self_approve_and_manager_requires_permission_and_team_scope(): void
    {
        [$owner, $staff, $manager, $team] = $this->people();
        $otherTeam = Team::query()->create(['name' => 'Other Team', 'status' => true]);
        $other = $this->user(EmployeeRole::Staff, $otherTeam);
        $sameTeamRecord = $this->pendingCompOff($staff->employee, $owner, '009991', '2026-09-15');
        $otherTeamRecord = $this->pendingCompOff($other->employee, $owner, '009992', '2026-09-16');
        $this->grant($staff, HrPermission::LeaveApprove, $owner);
        $this->grant($manager, HrPermission::LeaveApprove, $owner);

        $this->expectException(AuthorizationException::class);
        try {
            app(CompensatoryOffService::class)->approve($sameTeamRecord, $staff);
        } finally {
            $this->assertSame('pending', $sameTeamRecord->fresh()->status);
            $approved = app(CompensatoryOffService::class)->approve($sameTeamRecord, $manager);
            $this->assertSame('approved', $approved->status);
            try {
                app(CompensatoryOffService::class)->approve($otherTeamRecord, $manager);
                $this->fail('Manager approved another Team.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($team->id, $manager->employee->team_id);
        }
    }

    public function test_schedule_and_holiday_management_is_owner_admin_only_by_default(): void
    {
        [$owner, $staff, $manager] = $this->people();
        $admin = $this->user(EmployeeRole::Admin);

        foreach ([$owner, $admin] as $authorized) {
            $this->actingAs($authorized)->get('/admin/hr/work-schedules')->assertOk();
            $this->actingAs($authorized)->get('/admin/hr/public-holidays')->assertOk();
        }
        foreach ([$manager, $staff] as $denied) {
            $this->actingAs($denied)->get('/admin/hr/work-schedules')->assertForbidden();
            $this->actingAs($denied)->get('/admin/hr/public-holidays')->assertForbidden();
        }
        $this->actingAs($staff)->get('/admin/hr/comp-off')->assertOk();
        $this->expectException(AuthorizationException::class);
        app(PublicHolidayService::class)->save(['name' => 'Unauthorized', 'holiday_date' => '2026-12-01'], $staff);
    }

    public function test_work_schedule_and_holiday_livewire_pages_render_operational_fields(): void
    {
        [$owner] = $this->people();

        $office = WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail();
        Livewire::actingAs($owner)->test(WorkSchedules::class)
            ->assertSee('Assign Schedule')->assertSee('Sunday earns Comp Off')->assertSee('Rotation Weeks')
            ->call('editPattern', $office->id)->call('saveSchedule')->assertHasNoErrors();
        $this->assertSame(7, $office->days()->count());
        $this->assertDatabaseHas('work_schedule_days', [
            'work_schedule_id' => $office->id, 'weekday' => 1, 'expected_start_time' => '09:00', 'expected_end_time' => '17:00',
        ]);
        Livewire::actingAs($owner)->test(PublicHolidays::class)
            ->assertSee('Holiday Name')->assertSee('No Public Holidays configured')
            ->set('name', 'National Holiday')->set('holidayDate', '2026-12-25')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('public_holidays', ['name' => 'National Holiday', 'holiday_date' => '2026-12-25 00:00:00', 'status' => 1]);
        Livewire::actingAs($owner)->test(CompOff::class)
            ->assertSee('Worked Sunday')->assertSee('actual qualifying Attendance evidence');
    }

    public function test_operational_schedule_pattern_is_immutable_and_future_version_is_required(): void
    {
        [$owner, $staff] = $this->people();
        $schedule = $this->schedule('IMMUTABLE', 1);
        $this->assignEmployee($schedule, $staff->employee, $owner);

        $this->expectException(ValidationException::class);
        app(WorkScheduleService::class)->update($schedule, [
            'name' => 'Changed', 'code' => 'IMMUTABLE', 'timezone' => 'Asia/Karachi',
            'schedule_type' => 'standard', 'cycle_length_weeks' => 1,
        ], $this->pattern(1), $owner);
    }

    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [
            $this->user(EmployeeRole::Owner, $team),
            $this->user(EmployeeRole::Staff, $team),
            $this->user(EmployeeRole::Manager, $team),
            $team,
        ];
    }

    private function user(EmployeeRole $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team?->id, 'status' => true,
        ]);

        return $user->refresh();
    }

    private function schedule(string $code, int $weeks, bool $alternative = false): WorkSchedule
    {
        $schedule = WorkSchedule::query()->create([
            'code' => $code, 'name' => str($code)->replace('_', ' ')->title(), 'timezone' => 'Asia/Karachi',
            'schedule_type' => $alternative ? 'alternative_weekend' : 'standard',
            'cycle_length_weeks' => $weeks, 'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => true,
        ]);
        foreach ($this->pattern($weeks, $alternative) as $day) {
            WorkScheduleDay::query()->create($day + ['work_schedule_id' => $schedule->id]);
        }

        return $schedule;
    }

    private function pattern(int $weeks, bool $alternative = false): array
    {
        $days = [];
        foreach (range(1, $weeks) as $week) {
            foreach (range(0, 6) as $weekday) {
                $working = ! in_array($weekday, [0, 6], true);
                if ($alternative && $week === 1 && $weekday === 0) {
                    $working = true;
                }
                $days[] = [
                    'cycle_week' => $week, 'weekday' => $weekday, 'is_working_day' => $working,
                    'expected_start_time' => $working ? '09:00' : null,
                    'expected_end_time' => $working ? '17:00' : null,
                    'break_start_time' => null, 'break_end_time' => null,
                    'earns_compensatory_off' => $alternative && $week === 1 && $weekday === 0,
                ];
            }
        }

        return $days;
    }

    private function assignEmployee(WorkSchedule $schedule, Employee $employee, User $actor, string $from = '2026-01-01'): void
    {
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id, 'employee_id' => $employee->id,
            'effective_from' => $from, 'assigned_by_user_id' => $actor->id, 'reason' => 'Test effective schedule',
        ]);
    }

    private function attendance(Employee $employee, WorkSchedule $schedule, string $date): void
    {
        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id, 'attendance_date' => $date, 'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'first_check_in_at' => "{$date} 09:00:00",
            'last_check_out_at' => "{$date} 17:00:00", 'worked_minutes' => 480, 'status' => AttendanceStatus::Present,
            'late_minutes' => 0, 'early_departure_minutes' => 0, 'source' => 'manual', 'is_overridden' => false, 'calculated_at' => now(),
        ]);
    }

    private function pendingCompOff(Employee $employee, User $actor, string $suffix, string $offDate): CompensatoryOff
    {
        return CompensatoryOff::query()->create([
            'reference' => "COF-2026-{$suffix}", 'employee_id' => $employee->id,
            'earned_work_date' => '2026-08-30', 'off_date' => $offDate, 'source' => 'scheduled_sunday',
            'status' => 'pending', 'granted_by_user_id' => $actor->id, 'reason' => 'Pending approval', 'idempotency_key' => fake()->uuid(),
        ]);
    }

    private function grant(User $user, HrPermission $permission, User $actor): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $user->employee->id, 'permission_key' => $permission->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $actor->id, 'reason' => 'B2 scoped test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }
}
