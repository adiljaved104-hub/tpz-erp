<?php

namespace Tests\Feature\Tasks;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeeRole;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskLinkedType;
use App\Enums\TaskPriority;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\MyTasksWidget;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskCompletionSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskLinkedRecordService;
use App\Services\Tasks\TaskQueryService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MyTasksWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_widget_shows_only_own_active_assignments_and_personal_counts(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Asif');
        $other = $this->user(EmployeeRole::Staff, 'Zaid');
        $service = app(TaskService::class);

        $pending = $this->task($owner, 'Pending for Asif', [$staff->employee->id]);
        $inProgress = $this->task($owner, 'In progress for Asif', [$staff->employee->id]);
        $service->start($inProgress, $staff);
        $waiting = $this->task($owner, 'Waiting for Asif', [$staff->employee->id]);
        $service->start($waiting, $staff);
        $service->wait($waiting->refresh(), 'Waiting for details', null, $staff);
        $awaiting = $this->task($owner, 'Awaiting confirmation', [$staff->employee->id]);
        $service->start($awaiting, $staff);
        $service->submitForCompletion($awaiting->refresh(), 'Done', $staff);
        $overdue = $this->task($owner, 'Overdue for Asif', [$staff->employee->id], dueAt: now()->subDay()->toDateTimeString());
        $otherTask = $this->task($owner, 'Other employee only', [$other->employee->id]);
        $completed = $this->task($owner, 'Completed for Asif', [$staff->employee->id]);
        $service->start($completed, $staff);
        $service->submitForCompletion($completed->refresh(), null, $staff);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('task_id', $completed->id)->sole(), $owner);
        $cancelled = $this->task($owner, 'Cancelled for Asif', [$staff->employee->id]);
        $service->cancel($cancelled, 'No longer required', $owner);

        $this->assertSame([
            'pending' => 2,
            'in_progress' => 1,
            'waiting' => 1,
            'awaiting_confirmation' => 1,
            'overdue' => 1,
        ], app(TaskQueryService::class)->personalSummary($staff));

        Livewire::actingAs($staff)->test(MyTasksWidget::class)
            ->assertSee($pending->reference)
            ->assertSee($inProgress->reference)
            ->assertSee($waiting->reference)
            ->assertSee($awaiting->reference)
            ->assertSee($overdue->reference)
            ->assertDontSee($otherTask->reference)
            ->assertDontSee($completed->reference)
            ->assertDontSee($cancelled->reference)
            ->assertSee('Awaiting Confirmation')
            ->assertSee('View All Tasks')
            ->assertSee(TaskResource::getUrl(parameters: ['tab' => 'my_tasks']), false);
    }

    public function test_multiple_and_entire_team_tasks_are_personal_but_team_queue_is_not(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $asif = $this->user(EmployeeRole::Staff, 'Asif', $team);
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid', $team);
        $multiple = $this->task($owner, 'Multiple Task', [$asif->employee->id, $zaid->employee->id], TaskAssignmentMode::MultipleEmployees);
        $entireTeam = $this->task($owner, 'Entire Team Task', [], TaskAssignmentMode::EntireTeam, $team->id);
        $teamQueue = $this->task($owner, 'Team Queue Task', [], TaskAssignmentMode::TeamQueue, $team->id);

        foreach ([$asif, $zaid] as $employee) {
            Livewire::actingAs($employee)->test(MyTasksWidget::class)
                ->assertSee($multiple->reference)
                ->assertSee($entireTeam->reference)
                ->assertDontSee($teamQueue->reference);
        }
    }

    public function test_one_employee_completion_does_not_remove_another_employees_assignment(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif');
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid');
        $task = $this->task($owner, 'Independent Task', [$asif->employee->id, $zaid->employee->id], TaskAssignmentMode::MultipleEmployees);
        $service = app(TaskService::class);
        $service->start($task, $asif);
        $service->submitForCompletion($task->refresh(), null, $asif);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('submitted_by_user_id', $asif->id)->sole(), $owner);

        Livewire::actingAs($asif)->test(MyTasksWidget::class)->assertDontSee($task->reference);
        Livewire::actingAs($zaid)->test(MyTasksWidget::class)->assertSee($task->reference)->assertSee('Assigned');
    }

    public function test_reassigned_task_disappears_from_the_previous_employee_widget(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $asif = $this->user(EmployeeRole::Staff, 'Asif');
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid');
        $task = $this->task($owner, 'Reassigned Task', [$asif->employee->id]);

        app(TaskService::class)->assignMany($task, [$zaid->employee->id], null, $owner);

        Livewire::actingAs($asif)->test(MyTasksWidget::class)->assertDontSee($task->reference);
        Livewire::actingAs($zaid)->test(MyTasksWidget::class)->assertSee($task->reference);
    }

    public function test_linked_record_security_and_personal_scope_apply_to_all_roles(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        $other = $this->user(EmployeeRole::Staff, 'Other');
        $product = Product::factory()->create();
        $restricted = $this->task(
            $owner,
            'Restricted Product Task',
            [$staff->employee->id],
            linkedType: TaskLinkedType::Product,
            linkedRecordId: $product->id,
        );
        $companyTask = $this->task($owner, 'Company Task', [$other->employee->id]);
        $ownerTask = $this->task($owner, 'Owner Personal', [$owner->employee->id]);
        $adminTask = $this->task($owner, 'Admin Personal', [$admin->employee->id]);

        Livewire::actingAs($staff)->test(MyTasksWidget::class)
            ->assertSee($restricted->reference)
            ->assertSee('Restricted Record');
        Livewire::actingAs($owner)->test(MyTasksWidget::class)
            ->assertSee($ownerTask->reference)
            ->assertDontSee($companyTask->reference)
            ->assertDontSee($adminTask->reference);
        Livewire::actingAs($admin)->test(MyTasksWidget::class)
            ->assertSee($adminTask->reference)
            ->assertDontSee($companyTask->reference)
            ->assertDontSee($ownerTask->reference);
    }

    public function test_zero_tasks_renders_a_compact_empty_state(): void
    {
        $staff = $this->user(EmployeeRole::Staff, 'Staff');

        Livewire::actingAs($staff)->test(MyTasksWidget::class)
            ->assertSee('You have no pending tasks right now.')
            ->assertSee('View All Tasks');

        $this->actingAs($staff)->get('/admin')
            ->assertOk()
            ->assertSee('Operational Overview')
            ->assertSee('Visible Open Tasks')
            ->assertDontSee('You have no pending tasks right now.');

        $this->assertTrue(MyTasksWidget::canView());
        $staff->employee->update(['status' => false]);
        $this->assertFalse(MyTasksWidget::canView());
    }

    public function test_dashboard_limits_rows_and_batch_loads_linked_records(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        foreach (range(1, 6) as $index) {
            $product = Product::factory()->create();
            $this->task(
                $owner,
                "Linked Task {$index}",
                [$owner->employee->id],
                linkedType: TaskLinkedType::Product,
                linkedRecordId: $product->id,
            );
        }

        $tasks = app(TaskQueryService::class)->personalDashboardTasks($owner);
        $this->assertCount(5, $tasks);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TaskLinkedRecordService::class)->contexts($tasks, $owner);
        $productQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from "products"'),
        );
        DB::disableQueryLog();

        $this->assertCount(1, $productQueries);
    }

    private function task(
        User $actor,
        string $title,
        array $employeeIds = [],
        ?TaskAssignmentMode $mode = null,
        ?int $teamId = null,
        ?string $dueAt = null,
        ?TaskLinkedType $linkedType = null,
        ?int $linkedRecordId = null,
    ): Task {
        $mode ??= count($employeeIds) > 1 ? TaskAssignmentMode::MultipleEmployees : TaskAssignmentMode::SingleEmployee;

        return app(TaskService::class)->create(new CreateTaskData(
            title: $title,
            description: null,
            priority: TaskPriority::Normal,
            assignedEmployeeId: null,
            assignedTeamId: $teamId,
            dueAt: $dueAt,
            followUpAt: null,
            linkedType: $linkedType,
            linkedRecordId: $linkedRecordId,
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
