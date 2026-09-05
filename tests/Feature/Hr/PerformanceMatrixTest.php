<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AttendanceStatus;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PerformancePermission;
use App\Enums\TaskAssignmentStatus;
use App\Enums\TaskCompletionSubmissionStatus;
use App\Enums\TaskPermission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Pages\Hr\Performance;
use App\Models\AttendancePolicy;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePermissionOverride;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletionSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Performance\PerformanceMatrixService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PerformanceMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-21 12:00:00');
    }

    public function test_staff_enters_own_performance_and_cannot_access_another_employee(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $other = $this->user(EmployeeRole::Staff, $team);

        $this->actingAs($staff)->get('/admin/hr/performance')->assertOk()->assertSee($staff->employee->name)->assertDontSee($other->employee->name);
        $this->actingAs($staff)->get('/admin/hr/performance?employee='.$other->employee->id)->assertForbidden();
        Livewire::actingAs($staff)->test(Performance::class)->assertSet('employeeId', $staff->employee->id);
    }

    public function test_manager_is_team_scoped_and_owner_sees_company_overview(): void
    {
        $teamA = Team::query()->create(['name' => 'Team A', 'status' => true]);
        $teamB = Team::query()->create(['name' => 'Team B', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager, $teamA);
        $sameTeam = $this->user(EmployeeRole::Staff, $teamA);
        $otherTeam = $this->user(EmployeeRole::Staff, $teamB);
        $owner = $this->user(EmployeeRole::Owner, $teamB);

        $managerRows = app(PerformanceMatrixService::class)->overview($manager, ...$this->august());
        $this->assertEqualsCanonicalizing([$manager->employee->id, $sameTeam->employee->id], $managerRows->pluck('employee.id')->all());
        $this->actingAs($manager)->get('/admin/hr/performance?employee='.$otherTeam->employee->id)->assertForbidden();
        $ownerRows = app(PerformanceMatrixService::class)->overview($owner, ...$this->august());
        $this->assertCount(4, $ownerRows);
        $this->actingAs($owner)->get('/admin/hr/performance')->assertOk()->assertSee('Employee Performance Overview');
    }

    public function test_employee_allow_and_deny_overrides_use_centralized_access_control(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $override = EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id, 'permission_key' => PerformancePermission::ViewOwn->value,
            'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id, 'reason' => 'Performance access test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);

        $this->actingAs($staff)->get('/admin/hr/performance')->assertForbidden();
        $override->forceFill(['effect' => EmployeePermissionEffect::Allow])->save();
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->actingAs($staff)->get('/admin/hr/performance')->assertOk();

        EmployeePermissionOverride::query()->create([
            'employee_id' => $owner->employee->id, 'permission_key' => PerformancePermission::ViewAll->value,
            'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id, 'reason' => 'Protected Owner test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($owner->employee->id);
        $this->actingAs($owner)->get('/admin/hr/performance')->assertOk();
    }

    public function test_individual_and_multi_employee_assignments_count_independently_while_team_queue_is_excluded(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $first = $this->user(EmployeeRole::Staff);
        $second = $this->user(EmployeeRole::Staff);
        $task = $this->task($owner, 'TSK-2026-990001');
        $this->assignment($task, $first->employee, $owner);
        $this->assignment($task, $second->employee, $owner);
        $queueTeam = Team::query()->create(['name' => 'Queue', 'status' => true]);
        $queue = $this->task($owner, 'TSK-2026-990002', ['assigned_team_id' => $queueTeam->id]);

        $rows = app(PerformanceMatrixService::class)->overview($owner, ...$this->august())->keyBy('employee.id');
        $this->assertSame(1, $rows[$first->employee->id]['tasks']['assigned']);
        $this->assertSame(1, $rows[$second->employee->id]['tasks']['assigned']);
        $this->assertSame(1, $rows[$first->employee->id]['tasks']['pending_assigned']);
        $this->assertDatabaseMissing('task_assignments', ['task_id' => $queue->id]);
    }

    public function test_awaiting_confirmation_and_timeliness_use_employee_submission_not_confirmation_time(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $pendingTask = $this->task($owner, 'TSK-2026-990003', ['due_at' => '2026-08-20 17:00:00']);
        $pendingAssignment = $this->assignment($pendingTask, $staff->employee, $owner, TaskAssignmentStatus::InProgress);
        $this->submission($pendingTask, $pendingAssignment, $staff, TaskCompletionSubmissionStatus::Pending, '2026-08-20 16:00:00');
        $completedTask = $this->task($owner, 'TSK-2026-990004', ['due_at' => '2026-08-10 17:00:00']);
        $completed = $this->assignment($completedTask, $staff->employee, $owner, TaskAssignmentStatus::Completed, '2026-08-12 10:00:00');
        $this->submission($completedTask, $completed, $staff, TaskCompletionSubmissionStatus::Confirmed, '2026-08-10 16:00:00', $owner, '2026-08-12 10:00:00');

        $metrics = app(PerformanceMatrixService::class)->employee($staff, $staff->employee, ...$this->august())['tasks'];
        $this->assertSame(1, $metrics['awaiting_confirmation']);
        $this->assertSame(0, $metrics['currently_overdue']);
        $this->assertSame(1, $metrics['confirmed_completed']);
        $this->assertSame(1, $metrics['completed_on_time']);
        $this->assertSame(0, $metrics['completed_late']);
    }

    public function test_late_submission_and_return_for_rework_are_factual_individual_metrics(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $task = $this->task($owner, 'TSK-2026-990005', ['due_at' => '2026-08-10 17:00:00']);
        $assignment = $this->assignment($task, $staff->employee, $owner, TaskAssignmentStatus::Completed, '2026-08-13 10:00:00');
        $this->submission($task, $assignment, $staff, TaskCompletionSubmissionStatus::Returned, '2026-08-11 10:00:00', $owner, '2026-08-11 12:00:00');
        $this->submission($task, $assignment, $staff, TaskCompletionSubmissionStatus::Confirmed, '2026-08-12 10:00:00', $owner, '2026-08-13 10:00:00');

        $metrics = app(PerformanceMatrixService::class)->employee($staff, $staff->employee, ...$this->august())['tasks'];
        $this->assertSame(1, $metrics['returned_for_rework']);
        $this->assertSame(1, $metrics['completed_late']);
        $this->assertSame(0, $metrics['completed_on_time']);
    }

    public function test_attendance_keeps_actual_absence_late_penalty_and_approved_context_separate(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $dates = ['2026-08-03', '2026-08-04', '2026-08-05'];
        foreach ($dates as $date) {
            $this->attendance($staff->employee, AttendanceStatus::Late, $date);
        }
        $this->attendance($staff->employee, AttendanceStatus::Present, '2026-08-06');
        $this->attendance($staff->employee, AttendanceStatus::Absent, '2026-08-07');
        $this->attendance($staff->employee, AttendanceStatus::ApprovedLeave, '2026-08-10');
        $this->attendance($staff->employee, AttendanceStatus::CompensatoryOff, '2026-08-11');
        $this->attendance($staff->employee, AttendanceStatus::WeekendOff, '2026-08-12');
        $this->attendance($staff->employee, AttendanceStatus::PublicHoliday, '2026-08-13');
        EmployeeAttendance::query()->where('employee_id', $staff->employee->id)
            ->whereDate('attendance_date', '2026-08-06')
            ->update(['early_departure_minutes' => 30]);

        $metrics = app(PerformanceMatrixService::class)->employee($staff, $staff->employee, ...$this->august())['attendance'];
        $this->assertTrue($metrics['has_data']);
        $this->assertSame(6, $metrics['scheduled_working_days']);
        $this->assertSame(1, $metrics['present']);
        $this->assertSame(3, $metrics['late']);
        $this->assertSame(1, $metrics['actual_absent']);
        $this->assertSame(1, $metrics['approved_leave']);
        $this->assertSame(1, $metrics['comp_off']);
        $this->assertSame(1, $metrics['scheduled_off']);
        $this->assertSame(1, $metrics['public_holiday']);
        $this->assertSame(1, $metrics['early_checkout_occurrences']);
        $this->assertSame(30, $metrics['early_checkout_minutes']);
        $this->assertSame('1.00', $metrics['late_penalty']);
    }

    public function test_no_attendance_records_show_no_data_instead_of_fabricated_absence(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $metrics = app(PerformanceMatrixService::class)->employee($staff, $staff->employee, ...$this->august())['attendance'];

        $this->assertFalse($metrics['has_data']);
        $this->assertSame(0, $metrics['actual_absent']);
        $this->actingAs($staff)->get('/admin/hr/performance')->assertOk()->assertSee('No attendance data available');
    }

    public function test_operational_work_counts_only_explicit_assignee_and_exposes_no_financial_values(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        Complaint::query()->create([
            'reference' => 'CMP-2026-990001', 'category' => ComplaintCategory::Other, 'description' => 'Assigned case',
            'status' => ComplaintStatus::Open, 'assigned_to_user_id' => $staff->id, 'opened_at' => '2026-08-10 10:00:00',
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        Complaint::query()->create([
            'reference' => 'CMP-2026-990002', 'category' => ComplaintCategory::Other, 'description' => 'Unassigned case',
            'status' => ComplaintStatus::Open, 'assigned_to_user_id' => null, 'opened_at' => '2026-08-10 10:00:00',
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);

        $operations = app(PerformanceMatrixService::class)->employee($staff, $staff->employee, ...$this->august())['operations'];
        $this->assertSame(1, $operations['complaints_handled']);
        $this->assertSame(1, $operations['complaints_open']);
        $this->assertArrayNotHasKey('claimed_amount', $operations);
        $this->assertArrayNotHasKey('profit', $operations);
        $this->actingAs($staff)->get('/admin/hr/performance')->assertOk()->assertDontSee('AED')->assertDontSee('Performance Score');
    }

    public function test_performance_access_does_not_grant_task_source_access_and_date_filters_apply(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $augustTask = $this->task($owner, 'TSK-2026-990006', ['created_at' => '2026-08-10 10:00:00']);
        $this->assignment($augustTask, $staff->employee, $owner, TaskAssignmentStatus::Assigned, null, '2026-08-10 10:00:00');
        $julyTask = $this->task($owner, 'TSK-2026-990007', ['created_at' => '2026-07-10 10:00:00']);
        $this->assignment($julyTask, $staff->employee, $owner, TaskAssignmentStatus::Assigned, null, '2026-07-10 10:00:00');
        $this->override($staff, TaskPermission::View->value, EmployeePermissionEffect::Deny, $owner);

        $august = app(PerformanceMatrixService::class)->employee($staff, $staff->employee, ...$this->august())['tasks'];
        [$julyFrom, $julyTo] = app(PerformanceMatrixService::class)->range('custom', '2026-07-01', '2026-07-31');
        $july = app(PerformanceMatrixService::class)->employee($staff, $staff->employee, $julyFrom, $julyTo)['tasks'];
        $this->assertSame(1, $august['assigned']);
        $this->assertSame(1, $july['assigned']);
        $this->actingAs($staff)->get('/admin/hr/performance')->assertOk()->assertDontSee('Open authorized Tasks');
        $this->actingAs($staff)->get('/admin/tasks')->assertForbidden();
    }

    private function user(EmployeeRole $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $team?->id, 'status' => true]);

        return $user->refresh();
    }

    private function task(User $creator, string $reference, array $attributes = []): Task
    {
        return Task::query()->create($attributes + [
            'reference' => $reference, 'title' => 'Performance Task', 'status' => TaskStatus::Assigned,
            'priority' => TaskPriority::Normal, 'created_by_user_id' => $creator->id,
            'idempotency_key' => (string) Str::uuid(), 'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    private function assignment(Task $task, Employee $employee, User $actor, TaskAssignmentStatus $status = TaskAssignmentStatus::Assigned, ?string $completedAt = null, string $createdAt = '2026-08-01 10:00:00'): TaskAssignment
    {
        return TaskAssignment::query()->create([
            'task_id' => $task->id, 'employee_id' => $employee->id, 'team_id_at_assignment' => $employee->team_id,
            'status' => $status, 'completed_at' => $completedAt, 'assigned_by_user_id' => $actor->id,
            'idempotency_key' => (string) Str::uuid(), 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function submission(Task $task, TaskAssignment $assignment, User $employee, TaskCompletionSubmissionStatus $status, string $submittedAt, ?User $decider = null, ?string $decidedAt = null): TaskCompletionSubmission
    {
        return TaskCompletionSubmission::query()->create([
            'task_id' => $task->id, 'task_assignment_id' => $assignment->id, 'status' => $status,
            'active_fingerprint' => $status === TaskCompletionSubmissionStatus::Pending ? "task:{$task->id}:assignment:{$assignment->id}" : null,
            'submitted_by_user_id' => $employee->id, 'submitted_at' => $submittedAt,
            'decided_by_user_id' => $status === TaskCompletionSubmissionStatus::Pending ? null : $decider?->id,
            'decided_at' => $status === TaskCompletionSubmissionStatus::Pending ? null : $decidedAt,
            'decision_reason' => $status === TaskCompletionSubmissionStatus::Returned ? 'Please revise the work' : null,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function attendance(Employee $employee, AttendanceStatus $status, string $date): void
    {
        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id, 'attendance_date' => $date,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => $status,
            'late_minutes' => $status === AttendanceStatus::Late ? 10 : 0, 'early_departure_minutes' => 0,
            'source' => 'system_derived', 'is_overridden' => false, 'calculated_at' => now(),
        ]);
    }

    private function override(User $user, string $permission, EmployeePermissionEffect $effect, User $actor): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $user->employee->id, 'permission_key' => $permission, 'effect' => $effect,
            'granted_by_user_id' => $actor->id, 'reason' => 'Performance security test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }

    private function august(): array
    {
        return [CarbonImmutable::parse('2026-08-01')->startOfDay(), CarbonImmutable::parse('2026-08-31')->endOfDay()];
    }
}
