<?php

namespace Tests\Feature\MyWork;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeeRole;
use App\Enums\TaskPriority;
use App\Filament\Pages\MyWork;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskCompletionSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\MyWork\MyWorkService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MyWorkDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_sees_navigation_and_only_personally_assigned_active_tasks(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $mine = $this->task($owner, $staff->employee->id, 'My persistent Task');
        $this->task($owner, $other->employee->id, 'Other employee Task');
        $this->actingAs($staff);
        $this->assertTrue(MyWork::shouldRegisterNavigation());
        $this->get(MyWork::getUrl())->assertOk()->assertSee($mine->reference)->assertSee('My persistent Task')->assertDontSee('Other employee Task')->assertDontSee('Team Work')->assertDontSee('Unassigned');
    }

    public function test_owner_my_work_is_personal_and_team_and_unassigned_are_separate(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $mine = $this->task($owner, $owner->employee->id, 'Owner Task');
        $teamTask = $this->task($owner, $staff->employee->id, 'Team Task');
        $unassigned = $this->task($owner, null, 'Unassigned Task');
        $service = app(MyWorkService::class);
        $this->assertSame([$mine->id], $service->query($owner, 'mine')->pluck('id')->all());
        $this->assertContains($teamTask->id, $service->query($owner, 'team')->pluck('id')->all());
        $this->assertSame([$unassigned->id], $service->query($owner, 'unassigned')->pluck('id')->all());
    }

    public function test_completed_cancelled_and_reassigned_tasks_leave_personal_queue(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $task = $this->task($owner, $staff->employee->id, 'Lifecycle Task');
        $service = app(TaskService::class);
        $service->start($task, $staff);
        $this->assertTrue(app(MyWorkService::class)->query($staff)->whereKey($task->id)->exists());
        $service->submitForCompletion($task->refresh(), null, $staff);
        $this->assertTrue(app(MyWorkService::class)->query($staff)->whereKey($task->id)->exists());
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('task_id', $task->id)->sole(), $owner);
        $this->assertFalse(app(MyWorkService::class)->query($staff)->whereKey($task->id)->exists());
        $reassigned = $this->task($owner, $staff->employee->id, 'Reassigned Task');
        $service->assign($reassigned, $other->employee->id, null, $owner);
        $this->assertFalse(app(MyWorkService::class)->query($staff)->whereKey($reassigned->id)->exists());
        $this->assertTrue(app(MyWorkService::class)->query($other)->whereKey($reassigned->id)->exists());
    }

    public function test_unassigned_livewire_assignment_refreshes_queue_without_creating_source_work(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $task = $this->task($owner, null, 'Assign from My Work');
        Livewire::actingAs($owner)->test(MyWork::class)->call('setViewMode', 'unassigned')->assertSee($task->reference)
            ->call('assignTask', $task->id, (string) $staff->employee->id)->assertNotified('Task assignment updated')->assertDontSee($task->reference);
        $this->assertNull($task->refresh()->assigned_employee_id);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $task->id, 'employee_id' => $staff->employee->id, 'status' => 'assigned']);
    }

    public function test_inactive_or_unlinked_user_is_denied_and_empty_state_is_clear(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff);
        Livewire::test(MyWork::class)->assertSee('You have no pending work right now.');
        $staff->employee->update(['status' => false]);
        $this->assertFalse(MyWork::canAccess());
        $unlinked = User::factory()->create();
        $this->actingAs($unlinked);
        $this->assertFalse(MyWork::canAccess());
    }

    private function user(EmployeeRole $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true, 'team_id' => $team?->id]);

        return $user->refresh();
    }

    private function task(User $owner, ?int $employeeId, string $title): Task
    {
        return app(TaskService::class)->create(new CreateTaskData($title, null, TaskPriority::Normal, $employeeId, null, now()->addDay()->toDateTimeString(), null, null, null, (string) Str::uuid()), $owner);
    }
}
