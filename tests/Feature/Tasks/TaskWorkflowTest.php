<?php

namespace Tests\Feature\Tasks;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\TaskEventType;
use App\Enums\TaskLinkedType;
use App\Enums\TaskPermission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\TaskCompletionSubmission;
use App\Models\TaskEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Tasks\TaskLinkedRecordService;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_allocates_reference_and_derives_pending_or_assigned_status(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $unassigned = $this->create($owner);
        $assigned = $this->create($owner, $staff->employee->id);
        $this->assertSame('TSK-'.now()->year.'-000001', $unassigned->reference);
        $this->assertSame(TaskStatus::Pending, $unassigned->status);
        $this->assertSame(TaskStatus::Assigned, $assigned->status);
        $this->assertNull($assigned->assigned_employee_id);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $assigned->id, 'employee_id' => $staff->employee->id, 'status' => 'assigned']);
        $this->assertDatabaseHas('task_events', ['task_id' => $unassigned->id, 'event_type' => TaskEventType::Created->value]);
    }

    public function test_assignment_is_single_owner_active_only_and_records_reassignment(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'Ops', 'status' => true]);
        $task = $this->create($owner);
        $service = app(TaskService::class);
        $service->assign($task, null, $team->id, $owner);
        $this->assertSame($team->id, $task->refresh()->assigned_team_id);
        $service->assign($task, $staff->employee->id, null, $owner);
        $this->assertNull($task->refresh()->assigned_team_id);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'event_type' => TaskEventType::Reassigned->value]);
        $staff->employee->update(['status' => false]);
        $this->expectException(ValidationException::class);
        $service->assign($task, $staff->employee->id, null, $owner);
    }

    public function test_dual_assignment_and_inactive_team_are_blocked(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'Inactive', 'status' => false]);
        try {
            app(TaskService::class)->create(new CreateTaskData('Invalid', null, TaskPriority::Normal, $staff->employee->id, $team->id, null, null, null, null, (string) Str::uuid()), $owner);
            $this->fail('Dual assignment must fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->expectException(ValidationException::class);
        app(TaskService::class)->create(new CreateTaskData('Invalid Team', null, TaskPriority::Normal, null, $team->id, null, null, null, null, (string) Str::uuid()), $owner);
    }

    public function test_lifecycle_is_atomic_idempotent_and_timeline_is_immutable(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $task = $this->create($owner, $staff->employee->id);
        $service = app(TaskService::class);
        $service->start($task, $staff);
        $startedEvents = TaskEvent::query()->where('task_id', $task->id)->where('event_type', 'started')->count();
        $service->start($task->refresh(), $staff);
        $this->assertSame($startedEvents, TaskEvent::query()->where('task_id', $task->id)->where('event_type', 'started')->count());
        $service->wait($task->refresh(), 'Waiting for response', now()->addDay()->toDateTimeString(), $staff);
        $service->comment($task->refresh(), 'Amazon requested another document.', $staff);
        $service->resume($task->refresh(), $staff);
        $service->submitForCompletion($task->refresh(), 'Done', $staff);
        $this->assertNotSame(TaskStatus::Completed, $task->refresh()->status);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('task_id', $task->id)->sole(), $owner);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertNotNull($task->completed_at);
        $this->expectException(TaskException::class);
        $task->events()->first()->update(['note' => 'tampered']);
    }

    public function test_cancel_requires_reason_and_reopen_is_owner_admin_only(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $task = $this->create($owner, $staff->employee->id);
        $service = app(TaskService::class);
        try {
            $service->cancel($task, '', $owner);
            $this->fail();
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $service->start($task, $staff);
        $service->submitForCompletion($task->refresh(), null, $staff);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('task_id', $task->id)->sole(), $owner);
        try {
            $service->reopen($task->refresh(), 'Correction', $staff);
            $this->fail();
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $service->reopen($task->refresh(), 'Correction approved', $owner);
        $this->assertSame(TaskStatus::InProgress, $task->refresh()->status);
        $this->assertNull($task->completed_at);
    }

    public function test_due_states_are_derived_and_terminal_tasks_are_not_overdue(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $task = $this->create($owner, null, now()->subDay()->toDateTimeString());
        $this->assertTrue($task->isOverdue());
        $this->assertStringContainsString('overdue', $task->dueLabel());
        $soon = $this->create($owner, null, now()->addHours(5)->toDateTimeString());
        $this->assertTrue($soon->isDueSoon());
        app(TaskService::class)->cancel($task, 'No longer needed', $owner);
        $this->assertFalse($task->refresh()->isOverdue());
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'event_type' => TaskEventType::Cancelled->value, 'note' => 'No longer needed']);
    }

    public function test_manager_visibility_is_team_scoped(): void
    {
        $team = Team::query()->create(['name' => 'Sales', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $manager->employee->update(['team_id' => $team->id]);
        $same = $this->user(EmployeeRole::Staff);
        $same->employee->update(['team_id' => $team->id]);
        $other = $this->user(EmployeeRole::Staff);
        $other->employee->update(['team_id' => $otherTeam->id]);
        $sameTask = $this->create($owner, $same->employee->id);
        $otherTask = $this->create($owner, $other->employee->id);
        $ids = app(TaskAuthorization::class)->scopeQuery(Task::query(), $manager->refresh())->pluck('id');
        $this->assertTrue($ids->contains($sameTask->id));
        $this->assertFalse($ids->contains($otherTask->id));
    }

    public function test_priority_and_due_date_changes_create_timeline_events(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $task = $this->create($owner);
        $due = now()->addDays(3)->toDateTimeString();
        app(TaskService::class)->update($task, ['priority' => TaskPriority::Critical, 'due_at' => $due], $owner);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'event_type' => TaskEventType::PriorityChanged->value]);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'event_type' => TaskEventType::DueDateChanged->value]);
    }

    public function test_staff_scope_and_permission_overrides_are_enforced_server_side(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $mine = $this->create($owner, $staff->employee->id);
        $others = $this->create($owner, $other->employee->id);
        $visible = app(TaskAuthorization::class)->scopeQuery(Task::query(), $staff)->pluck('id');
        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($others->id));
        $this->override($staff, $owner, TaskPermission::View, EmployeePermissionEffect::Deny);
        $this->assertFalse(app(TaskAuthorization::class)->allows($staff, TaskPermission::View));
        $this->actingAs($staff)->get(TaskResource::getUrl())->assertForbidden();
    }

    public function test_create_permission_does_not_bypass_assignment_permission(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $target = $this->user(EmployeeRole::Staff);
        $this->override($staff, $owner, TaskPermission::Create, EmployeePermissionEffect::Allow);

        $this->expectException(AuthorizationException::class);
        app(TaskService::class)->create(new CreateTaskData('Unauthorized assignment', null, TaskPriority::Normal, $target->employee->id, null, null, null, null, null, (string) Str::uuid()), $staff);
    }

    public function test_linked_record_is_restricted_without_source_permission_and_task_does_not_mutate_source_or_inventory(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $product = Product::factory()->create();
        $inventoryBefore = ProductInventory::query()->get()->toArray();
        $movements = StockMovement::query()->count();
        $task = app(TaskService::class)->create(new CreateTaskData('Review Product', null, TaskPriority::Normal, $staff->employee->id, null, null, null, TaskLinkedType::Product, $product->id, (string) Str::uuid()), $owner);
        $this->assertSame('Restricted Record', app(TaskLinkedRecordService::class)->context($task, $staff)['label']);
        $this->assertSame($inventoryBefore, ProductInventory::query()->get()->toArray());
        $this->assertSame($movements, StockMovement::query()->count());
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function create(User $actor, ?int $employeeId = null, ?string $due = null): Task
    {
        return app(TaskService::class)->create(new CreateTaskData('Operational Task', null, TaskPriority::Normal, $employeeId, null, $due, null, null, null, (string) Str::uuid()), $actor);
    }

    private function override(User $target, User $actor, TaskPermission $permission, EmployeePermissionEffect $effect): void
    {
        EmployeePermissionOverride::query()->create(['employee_id' => $target->employee->id, 'permission_key' => $permission->value, 'effect' => $effect, 'granted_by_user_id' => $actor->id, 'reason' => 'Task test']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($target->employee->id);
    }
}
