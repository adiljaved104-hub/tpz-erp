<?php

namespace Tests\Feature\Tasks;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPermission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Pages\MyWork;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Task;
use App\Models\TaskCompletionSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TaskAssignmentModesTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_and_cross_team_multiple_modes_create_individual_team_snapshots(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $sales = Team::query()->create(['name' => 'Sales', 'status' => true]);
        $ecommerce = Team::query()->create(['name' => 'E-commerce', 'status' => true]);
        $asif = $this->user(EmployeeRole::Staff, 'Asif', $sales);
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid', $ecommerce);

        $single = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$asif->employee->id]);
        $multiple = $this->task($owner, TaskAssignmentMode::MultipleEmployees, [$asif->employee->id, $zaid->employee->id]);

        $this->assertDatabaseHas('task_assignments', ['task_id' => $single->id, 'employee_id' => $asif->employee->id, 'team_id_at_assignment' => $sales->id, 'team_name_at_assignment' => 'Sales']);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $multiple->id, 'employee_id' => $asif->employee->id, 'team_id_at_assignment' => $sales->id]);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $multiple->id, 'employee_id' => $zaid->employee->id, 'team_id_at_assignment' => $ecommerce->id]);
        $this->assertNull($multiple->assigned_team_id);
    }

    public function test_entire_team_expands_only_active_eligible_members_and_appears_in_personal_work(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $asif = $this->user(EmployeeRole::Staff, 'Asif', $team);
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid', $team);
        $inactive = $this->user(EmployeeRole::Staff, 'Inactive', $team);
        $inactive->employee->update(['status' => false]);

        $task = $this->task($owner, TaskAssignmentMode::EntireTeam, teamId: $team->id);

        $this->assertSame(2, $task->assignments()->count());
        $this->assertDatabaseMissing('task_assignments', ['task_id' => $task->id, 'employee_id' => $inactive->employee->id]);
        $this->actingAs($asif)->get(MyWork::getUrl())->assertOk()->assertSee($task->reference);
        $this->actingAs($zaid)->get(MyWork::getUrl())->assertOk()->assertSee($task->reference);
    }

    public function test_entire_team_form_previews_recipients_and_creates_each_assignment(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $this->user(EmployeeRole::Staff, 'Asif', $team);
        $this->user(EmployeeRole::Staff, 'Zaid', $team);

        Livewire::actingAs($owner)->test(CreateTask::class)->fillForm([
            'title' => 'Entire Team UI Task',
            'priority' => TaskPriority::Normal->value,
            'assignment_mode' => TaskAssignmentMode::EntireTeam->value,
            'assigned_team_id' => $team->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSee('2 employees will receive this Task.')
            ->call('create')->assertHasNoFormErrors();

        $task = Task::query()->where('title', 'Entire Team UI Task')->sole();
        $this->assertSame(2, $task->assignments()->count());
        Livewire::actingAs($owner)->test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertFormSet(['assignment_mode' => TaskAssignmentMode::EntireTeam, 'assigned_team_id' => $team->id]);
    }

    public function test_entire_team_retry_and_duplicate_employee_input_do_not_duplicate_assignments(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $asif = $this->user(EmployeeRole::Staff, 'Asif', $team);
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid', $team);
        $task = $this->task($owner, TaskAssignmentMode::EntireTeam, teamId: $team->id);
        $events = $task->events()->count();

        app(TaskService::class)->assignByMode($task, TaskAssignmentMode::EntireTeam, [], $team->id, $owner);
        app(TaskService::class)->assignByMode($task, TaskAssignmentMode::MultipleEmployees, [
            $asif->employee->id, $asif->employee->id, $zaid->employee->id,
        ], null, $owner);

        $this->assertSame(2, $task->assignments()->count());
        $this->assertSame($events, $task->events()->count());
    }

    public function test_team_queue_remains_team_owned_and_out_of_personal_work(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'E-commerce Operations', 'status' => true]);
        $asif = $this->user(EmployeeRole::Staff, 'Asif', $team);

        $task = $this->task($owner, TaskAssignmentMode::TeamQueue, teamId: $team->id);

        $this->assertSame($team->id, $task->assigned_team_id);
        $this->assertSame('Team — E-commerce Operations', $task->assignmentLabel());
        $this->assertDatabaseMissing('task_assignments', ['task_id' => $task->id]);
        $this->actingAs($asif)->get(MyWork::getUrl())->assertOk()->assertDontSee($task->reference);
    }

    public function test_entire_team_progress_and_completion_remain_independent(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $asif = $this->user(EmployeeRole::Staff, 'Asif', $team);
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid', $team);
        $task = $this->task($owner, TaskAssignmentMode::EntireTeam, teamId: $team->id);
        $service = app(TaskService::class);

        $service->start($task, $asif);
        $service->submitForCompletion($task->refresh(), 'Asif done', $asif);
        $progress = $task->refresh()->load(['assignments', 'completionSubmissions'])->assignmentProgress();
        $this->assertSame(['total' => 2, 'in_progress' => 1, 'awaiting_confirmation' => 1, 'confirmed' => 0], $progress);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('submitted_by_user_id', $asif->id)->sole(), $owner);
        $this->assertNotSame(TaskStatus::Completed, $task->refresh()->status);

        $service->start($task->refresh(), $zaid);
        $service->submitForCompletion($task->refresh(), 'Zaid done', $zaid);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('submitted_by_user_id', $zaid->id)->sole(), $owner);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
    }

    public function test_manager_can_assign_only_within_their_team_scope(): void
    {
        $team = Team::query()->create(['name' => 'Managed', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager, 'Manager', $team);
        $this->user(EmployeeRole::Staff, 'Managed Staff', $team);
        $this->user(EmployeeRole::Staff, 'Other Staff', $otherTeam);

        $task = $this->task($manager, TaskAssignmentMode::EntireTeam, teamId: $team->id);
        $this->assertSame(2, $task->assignments()->count());

        $this->expectException(AuthorizationException::class);
        $this->task($manager, TaskAssignmentMode::EntireTeam, teamId: $otherTeam->id);
    }

    public function test_staff_with_create_but_without_assign_cannot_bulk_assign(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $staff = $this->user(EmployeeRole::Staff, 'Staff', $team);
        $other = $this->user(EmployeeRole::Staff, 'Other', $team);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => TaskPermission::Create->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Task mode test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);

        $this->expectException(AuthorizationException::class);
        $this->task($staff, TaskAssignmentMode::MultipleEmployees, [$staff->employee->id, $other->employee->id]);
    }

    /** @param array<int, int> $employeeIds */
    private function task(User $actor, TaskAssignmentMode $mode, array $employeeIds = [], ?int $teamId = null): Task
    {
        return app(TaskService::class)->create(new CreateTaskData(
            title: 'Assignment Mode Task',
            description: null,
            priority: TaskPriority::Normal,
            assignedEmployeeId: null,
            assignedTeamId: $teamId,
            dueAt: null,
            followUpAt: null,
            linkedType: null,
            linkedRecordId: null,
            idempotencyKey: (string) Str::uuid(),
            assignedEmployeeIds: $employeeIds,
            assignmentMode: $mode,
        ), $actor);
    }

    private function user(EmployeeRole $role, string $name, ?Team $team = null): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name,
            'email' => $user->email,
            'status' => true,
            'team_id' => $team?->id,
        ]);

        return $user->refresh();
    }
}
