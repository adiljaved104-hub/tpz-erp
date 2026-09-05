<?php

namespace Tests\Feature\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Hr\WorkSchedules;
use App\Models\ActivityLog;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hr\EffectiveWorkScheduleResolver;
use App\Services\Hr\WorkScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WorkScheduleEndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_ends_employee_assignment_from_the_livewire_action_without_changing_attendance(): void
    {
        [$owner, $employee, $team] = $this->people();
        $schedule = $this->schedule('EMPLOYEE_END');
        $assignment = $this->assignment($schedule, $owner, employee: $employee->employee, to: '2028-09-01');
        $attendance = $this->attendance($employee->employee, $schedule, '2026-08-15');
        $attendanceFingerprint = $attendance->refresh()->getRawOriginal();

        Livewire::actingAs($owner)->test(WorkSchedules::class)
            ->assertSee('Employee — '.$employee->employee->name)
            ->assertActionExists('endAssignment', arguments: ['assignmentId' => $assignment->id])
            ->mountAction('endAssignment', arguments: ['assignmentId' => $assignment->id])
            ->assertActionMounted('endAssignment')
            ->assertActionDataSet([
                'target' => $employee->employee->name,
                'current_range' => '01 Aug 2026 – 01 Sep 2028',
            ])
            ->setActionData([
                'end_date' => '2026-08-31',
                'reason' => 'Employee-specific schedule is no longer required.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Schedule assignment ended');

        $this->assertDatabaseCount('work_schedule_assignments', 1);
        $this->assertSame('2026-08-31', $assignment->fresh()->effective_to->toDateString());
        $this->assertSame($attendanceFingerprint, $attendance->fresh()->getRawOriginal());
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'work_schedule.assignment_ended',
            'subject_type' => 'work_schedule_assignment',
            'subject_id' => $assignment->id,
            'actor_user_id' => $owner->id,
        ]);
    }

    public function test_authorized_user_can_end_team_assignment_and_preserve_its_row(): void
    {
        [$owner, , $team] = $this->people();
        $schedule = $this->schedule('TEAM_END');
        $assignment = $this->assignment($schedule, $owner, team: $team);

        app(WorkScheduleService::class)->endAssignment($assignment, '2026-09-30', 'Team schedule changes next month.', $owner);

        $this->assertDatabaseCount('work_schedule_assignments', 1);
        $this->assertSame('2026-09-30', $assignment->fresh()->effective_to->toDateString());
    }

    public function test_unauthorized_user_cannot_end_an_assignment(): void
    {
        [$owner, $staff] = $this->people();
        $assignment = $this->assignment($this->schedule('DENIED_END'), $owner, employee: $staff->employee);

        try {
            app(WorkScheduleService::class)->endAssignment($assignment, '2026-08-31', 'Unauthorized attempt.', $staff);
            $this->fail('Unauthorized assignment ending was accepted.');
        } catch (AuthorizationException) {
            $this->assertNull($assignment->fresh()->effective_to);
        }

        $this->actingAs($staff)->get('/admin/hr/work-schedules')->assertForbidden();
    }

    public function test_end_date_before_effective_from_is_rejected_without_an_audit_event(): void
    {
        [$owner, $staff] = $this->people();
        $assignment = $this->assignment($this->schedule('INVALID_END'), $owner, employee: $staff->employee, from: '2026-08-10');

        try {
            app(WorkScheduleService::class)->endAssignment($assignment, '2026-08-09', 'Invalid range test.', $owner);
            $this->fail('An invalid assignment range was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('end_date', $exception->errors());
        }

        $this->assertNull($assignment->fresh()->effective_to);
        $this->assertFalse(ActivityLog::query()->where('event', 'work_schedule.assignment_ended')->exists());
    }

    public function test_team_schedule_takes_over_after_employee_assignment_ends_while_history_remains_resolvable(): void
    {
        [$owner, $staff, $team] = $this->people();
        $teamSchedule = $this->schedule('TEAM_FALLBACK');
        $employeeSchedule = $this->schedule('EMPLOYEE_OVERRIDE');
        $this->assignment($teamSchedule, $owner, team: $team, from: '2026-01-01');
        $employeeAssignment = $this->assignment($employeeSchedule, $owner, employee: $staff->employee, from: '2026-08-01');

        app(WorkScheduleService::class)->endAssignment($employeeAssignment, '2026-08-31', 'Return Employee to Team schedule.', $owner);

        $resolver = app(EffectiveWorkScheduleResolver::class);
        $this->assertSame($employeeSchedule->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-08-15'))->work_schedule_id);
        $this->assertSame($employeeSchedule->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-08-31'))->work_schedule_id);
        $this->assertSame($teamSchedule->id, $resolver->assignment($staff->employee, CarbonImmutable::parse('2026-09-01'))->work_schedule_id);
        $this->assertDatabaseCount('work_schedule_assignments', 2);
    }

    public function test_assignment_status_display_distinguishes_active_ends_today_and_ended_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-22 10:00:00', 'Asia/Karachi'));
        [$owner, $staff] = $this->people();
        $future = $this->assignment($this->schedule('ACTIVE_DISPLAY'), $owner, employee: $staff->employee, from: '2026-08-01', to: '2026-08-23');
        $this->assignment($this->schedule('ENDS_TODAY_DISPLAY'), $owner, employee: $staff->employee, from: '2026-08-02', to: '2026-08-22');
        $this->assignment($this->schedule('ENDED_DISPLAY'), $owner, employee: $staff->employee, from: '2026-08-03', to: '2026-08-21');

        $component = Livewire::actingAs($owner)->test(WorkSchedules::class)
            ->assertSee('Ends Today')
            ->assertSee('Ended')
            ->assertSee('1 current')
            ->assertSee('0 current')
            ->assertSee('1 ended');

        $this->assertSame(1, preg_match_all('/>\s*End Assignment\s*</', $component->html()));
        $component->assertActionExists('endAssignment', arguments: ['assignmentId' => $future->id]);
    }

    /** @return array{User, User, Team} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [
            $this->user(EmployeeRole::Owner, $team),
            $this->user(EmployeeRole::Staff, $team),
            $team,
        ];
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'team_id' => $team->id,
            'status' => true,
        ]);

        return $user->refresh();
    }

    private function schedule(string $code): WorkSchedule
    {
        $schedule = WorkSchedule::query()->create([
            'code' => $code,
            'name' => str($code)->replace('_', ' ')->title(),
            'timezone' => 'Asia/Karachi',
            'schedule_type' => 'standard',
            'cycle_length_weeks' => 1,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'status' => true,
        ]);
        foreach (range(0, 6) as $weekday) {
            $working = ! in_array($weekday, [0, 6], true);
            WorkScheduleDay::query()->create([
                'work_schedule_id' => $schedule->id,
                'cycle_week' => 1,
                'weekday' => $weekday,
                'is_working_day' => $working,
                'expected_start_time' => $working ? '09:00' : null,
                'expected_end_time' => $working ? '17:00' : null,
                'earns_compensatory_off' => false,
            ]);
        }

        return $schedule;
    }

    private function assignment(
        WorkSchedule $schedule,
        User $actor,
        ?Employee $employee = null,
        ?Team $team = null,
        string $from = '2026-08-01',
        ?string $to = null,
    ): WorkScheduleAssignment {
        return WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id,
            'employee_id' => $employee?->id,
            'team_id' => $team?->id,
            'effective_from' => $from,
            'effective_to' => $to,
            'assigned_by_user_id' => $actor->id,
            'reason' => 'Focused assignment fixture.',
        ]);
    }

    private function attendance(Employee $employee, WorkSchedule $schedule, string $date): EmployeeAttendance
    {
        return EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'first_check_in_at' => "{$date} 09:00:00",
            'last_check_out_at' => "{$date} 17:00:00",
            'worked_minutes' => 480,
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'source' => 'manual',
            'is_overridden' => false,
            'calculated_at' => now(),
        ]);
    }
}
