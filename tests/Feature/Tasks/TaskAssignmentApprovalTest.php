<?php

namespace Tests\Feature\Tasks;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeeRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Pages\MyWork;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskCompletionSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaskAssignmentApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_employee_selection_creates_real_assignment_visible_in_my_tasks_and_my_work(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif Hameed');
        $other = $this->user(EmployeeRole::Staff, 'Other Staff');

        Livewire::actingAs($owner)->test(CreateTask::class)->fillForm([
            'title' => 'Asif acceptance task',
            'priority' => TaskPriority::Normal->value,
            'assignment_mode' => 'single_employee',
            'assigned_employee_id' => $asif->employee->id,
            'assigned_employee_ids' => [],
            'assigned_team_id' => null,
            'idempotency_key' => (string) Str::uuid(),
        ])->call('create')->assertHasNoFormErrors();

        $task = Task::query()->where('title', 'Asif acceptance task')->sole();
        $this->assertNull($task->assigned_employee_id);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $task->id, 'employee_id' => $asif->employee->id, 'status' => 'assigned']);
        $this->actingAs($asif)->get(TaskResource::getUrl())->assertOk()->assertSee($task->reference);
        $this->get(MyWork::getUrl())->assertOk()->assertSee($task->reference);
        $this->actingAs($other)->get(TaskResource::getUrl())->assertOk()->assertDontSee($task->reference);
    }

    public function test_team_only_task_is_labelled_and_does_not_become_personal_work(): void
    {
        $team = Team::query()->create(['name' => 'E-commerce Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif Hameed', $team);
        $task = $this->task($owner, [], $team->id);

        $this->assertSame('Team — E-commerce Operations', $task->assignmentLabel());
        $this->assertDatabaseCount('task_assignments', 0);
        $this->actingAs($asif)->get(TaskResource::getUrl())->assertOk()->assertDontSee($task->reference);
        $this->get(MyWork::getUrl())->assertOk()->assertDontSee($task->reference);
    }

    public function test_multiple_employee_assignments_have_independent_lifecycles_and_completion(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif Hameed');
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid');
        $task = $this->task($owner, [$asif->employee->id, $zaid->employee->id]);
        $service = app(TaskService::class);

        $this->assertDatabaseCount('task_assignments', 2);
        $service->start($task, $asif);
        $service->submitForCompletion($task->refresh(), 'Asif done', $asif);
        $asifSubmission = TaskCompletionSubmission::query()->where('submitted_by_user_id', $asif->id)->sole();
        $this->assertSame(TaskStatus::InProgress, $task->refresh()->status);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $task->id, 'employee_id' => $zaid->employee->id, 'status' => 'assigned']);

        $service->confirmCompletion($asifSubmission, $owner);
        $this->assertNotSame(TaskStatus::Completed, $task->refresh()->status);
        $service->start($task->refresh(), $zaid);
        $service->submitForCompletion($task->refresh(), 'Zaid done', $zaid);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('submitted_by_user_id', $zaid->id)->sole(), $owner);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
    }

    public function test_worker_actions_are_assignee_only_and_return_requires_reason(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif Hameed');
        $task = $this->task($owner, [$asif->employee->id]);

        try {
            app(TaskService::class)->start($task, $owner);
            $this->fail('A non-assignee must not perform worker actions.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        app(TaskService::class)->start($task, $asif);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $task->id, 'employee_id' => $asif->employee->id, 'status' => 'in_progress']);
    }

    public function test_submission_awaits_confirmation_and_can_be_returned_with_reason(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif Hameed');
        $task = $this->task($owner, [$asif->employee->id]);
        $service = app(TaskService::class);
        $service->start($task, $asif);
        $service->comment($task->refresh(), 'Sir working on it', $asif);
        $service->submitForCompletion($task->refresh(), 'Ready', $asif);
        $submission = TaskCompletionSubmission::query()->sole();

        $this->assertTrue($task->refresh()->hasPendingCompletion());
        $this->assertNotSame(TaskStatus::Completed, $task->status);
        try {
            $service->returnToEmployee($submission, '', $owner);
            $this->fail('A reason is mandatory.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $service->returnToEmployee($submission, 'Please attach evidence.', $owner);
        $this->assertDatabaseHas('task_completion_submission_events', ['task_completion_submission_id' => $submission->id, 'event_type' => 'returned', 'reason' => 'Please attach evidence.']);
        $this->assertSame(TaskStatus::InProgress, $task->refresh()->status);
        $this->actingAs($owner)->get(TaskResource::getUrl(parameters: ['tab' => 'team_tasks']))->assertOk()->assertSee('Sir working on it')->assertSee('Asif Hameed');
    }

    public function test_completed_task_hides_edit_and_worker_actions(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif Hameed');
        $task = $this->task($owner, [$asif->employee->id]);
        $service = app(TaskService::class);
        $service->start($task, $asif);
        $service->submitForCompletion($task->refresh(), null, $asif);
        $service->confirmCompletion(TaskCompletionSubmission::query()->sole(), $owner);

        $this->actingAs($owner);
        $this->assertFalse(TaskResource::canEdit($task->refresh()));
        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertActionHidden('edit')
            ->assertActionHidden('start')
            ->assertActionHidden('submit_completion');
    }

    public function test_legacy_employee_task_remains_usable_without_inventing_assignment_history(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif Hameed');
        $task = Task::query()->create([
            'reference' => 'TSK-2026-009999',
            'title' => 'Legacy Task',
            'status' => TaskStatus::Assigned,
            'priority' => TaskPriority::Normal,
            'assigned_employee_id' => $asif->employee->id,
            'assigned_team_id' => null,
            'created_by_user_id' => $owner->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        app(TaskService::class)->start($task, $asif);
        app(TaskService::class)->submitForCompletion($task->refresh(), 'Legacy done', $asif);
        $submission = TaskCompletionSubmission::query()->where('task_id', $task->id)->sole();
        $this->assertNull($submission->task_assignment_id);
        $this->assertDatabaseCount('task_assignments', 0);
        app(TaskService::class)->confirmCompletion($submission, $owner);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertDatabaseCount('task_assignment_events', 0);
    }

    private function task(User $owner, array $employeeIds = [], ?int $teamId = null): Task
    {
        return app(TaskService::class)->create(new CreateTaskData(
            title: 'Supervised Task',
            description: null,
            priority: TaskPriority::Normal,
            assignedEmployeeId: null,
            assignedTeamId: $teamId,
            dueAt: now()->addDay()->toDateTimeString(),
            followUpAt: null,
            linkedType: null,
            linkedRecordId: null,
            idempotencyKey: (string) Str::uuid(),
            assignedEmployeeIds: $employeeIds,
        ), $owner);
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
