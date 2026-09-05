<?php

namespace Tests\Feature\Notifications;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeeRole;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPriority;
use App\Filament\Pages\Notifications;
use App\Livewire\NotificationBell;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskCompletionSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Notifications\NotificationInboxService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TaskNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_modes_notify_only_individual_recipients_and_are_idempotent(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $asif = $this->user(EmployeeRole::Staff, 'Asif', $team);
        $zaid = $this->user(EmployeeRole::Staff, 'Zaid', $team);
        $unrelated = $this->user(EmployeeRole::Staff, 'Unrelated');

        $single = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$asif->employee->id]);
        $multiple = $this->task($owner, TaskAssignmentMode::MultipleEmployees, [$asif->employee->id, $zaid->employee->id]);
        $entire = $this->task($owner, TaskAssignmentMode::EntireTeam, teamId: $team->id);
        $queue = $this->task($owner, TaskAssignmentMode::TeamQueue, teamId: $team->id);

        $this->assertSame(3, $asif->notifications()->where('type', 'task.assigned')->count());
        $this->assertSame(2, $zaid->notifications()->where('type', 'task.assigned')->count());
        $this->assertSame(0, $unrelated->notifications()->count());
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertFalse($asif->notifications()->get()->contains(fn ($notification) => data_get($notification->data, 'target_id') === $queue->id));

        app(TaskService::class)->assignByMode($multiple, TaskAssignmentMode::MultipleEmployees, [$asif->employee->id, $zaid->employee->id], null, $owner);
        app(TaskService::class)->assignByMode($entire, TaskAssignmentMode::EntireTeam, [], $team->id, $owner);
        $this->assertSame(3, $asif->notifications()->where('type', 'task.assigned')->count());
        $this->assertTrue($asif->notifications()->get()->contains(
            fn ($notification): bool => (int) data_get($notification->data, 'target_id') === $single->id,
        ));
    }

    public function test_submission_notifies_only_authorized_approvers_and_decisions_notify_assignee(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        $manager = $this->user(EmployeeRole::Manager, 'Manager', $team);
        $outsideManager = $this->user(EmployeeRole::Manager, 'Outside Manager', $otherTeam);
        $staff = $this->user(EmployeeRole::Staff, 'Staff', $team);
        $task = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$staff->employee->id]);
        $service = app(TaskService::class);

        $service->start($task, $staff);
        $service->submitForCompletion($task->refresh(), 'Done', $staff);
        $submission = TaskCompletionSubmission::query()->sole();

        foreach ([$owner, $admin, $manager] as $approver) {
            $this->assertSame(1, $approver->notifications()->where('type', 'task.awaiting_confirmation')->count());
        }
        $this->assertSame(0, $outsideManager->notifications()->where('type', 'task.awaiting_confirmation')->count());

        $service->returnToEmployee($submission, 'Please attach evidence', $owner);
        $returned = $staff->notifications()->where('type', 'task.returned')->sole();
        $this->assertStringContainsString('Please attach evidence', data_get($returned->data, 'message'));

        $service->submitForCompletion($task->refresh(), 'Evidence attached', $staff);
        $service->confirmCompletion(TaskCompletionSubmission::query()->where('status', 'pending')->sole(), $owner);
        $this->assertSame(1, $staff->notifications()->where('type', 'task.completion_confirmed')->count());
    }

    public function test_due_command_is_idempotent_and_excludes_pending_completion_and_inactive_employee(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 10:00:00');
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $active = $this->user(EmployeeRole::Staff, 'Active');
        $inactive = $this->user(EmployeeRole::Staff, 'Inactive');
        $due = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$active->employee->id], dueAt: now()->addHours(12)->toDateTimeString());
        $pending = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$active->employee->id], dueAt: now()->addHours(10)->toDateTimeString());
        $this->task($owner, TaskAssignmentMode::SingleEmployee, [$inactive->employee->id], dueAt: now()->subHour()->toDateTimeString());
        $completed = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$active->employee->id], dueAt: now()->subHour()->toDateTimeString());
        $cancelled = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$active->employee->id], dueAt: now()->subHour()->toDateTimeString());
        app(TaskService::class)->start($completed, $active);
        app(TaskService::class)->submitForCompletion($completed->refresh(), null, $active);
        app(TaskService::class)->confirmCompletion(TaskCompletionSubmission::query()->where('task_id', $completed->id)->sole(), $owner);
        app(TaskService::class)->cancel($cancelled, 'No longer required', $owner);
        $inactive->employee->update(['status' => false]);
        app(TaskService::class)->start($pending, $active);
        app(TaskService::class)->submitForCompletion($pending->refresh(), null, $active);
        $active->notifications()->delete();
        $inactive->notifications()->delete();

        $this->artisan('tasks:send-due-notifications')->assertSuccessful();
        $this->artisan('tasks:send-due-notifications')->assertSuccessful();
        $this->assertSame(1, $active->notifications()->where('type', 'task.due_soon')->count());
        $this->assertSame($due->id, (int) data_get($active->notifications()->sole()->data, 'target_id'));
        $this->assertSame(0, $inactive->notifications()->count());

        CarbonImmutable::setTestNow('2026-08-21 00:00:00');
        $this->artisan('tasks:send-due-notifications')->assertSuccessful();
        $this->assertSame(1, $active->notifications()->where('type', 'task.overdue')->count());
        CarbonImmutable::setTestNow();
    }

    public function test_inbox_and_bell_are_recipient_scoped_and_reauthorize_targets(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        $other = $this->user(EmployeeRole::Staff, 'Other');
        $task = $this->task($owner, TaskAssignmentMode::SingleEmployee, [$staff->employee->id]);
        $notification = $staff->notifications()->sole();

        Livewire::actingAs($staff)->test(NotificationBell::class)
            ->assertSet('unreadCount', 1)
            ->assertSee('New Task Assigned')
            ->assertSee('tpz-notification-sound')
            ->call('markRead', $notification->id)
            ->assertSet('unreadCount', 0);

        $notification->update(['read_at' => null]);
        $this->actingAs($staff)->get(Notifications::getUrl())->assertOk()->assertSee($task->reference);
        $this->actingAs($other)->get(Notifications::getUrl())->assertOk()->assertDontSee($task->reference);

        app(TaskService::class)->assign($task, $other->employee->id, null, $owner);
        $this->assertSame(1, $other->notifications()->where('type', 'task.reassigned')->count());
        $result = app(NotificationInboxService::class)->open($staff, $notification->id);
        $this->assertFalse($result['available']);
        $this->assertNotNull($notification->fresh()->read_at);

        $this->expectException(ModelNotFoundException::class);
        app(NotificationInboxService::class)->markRead($staff, $other->notifications()->sole()->id);
    }

    /** @param array<int, int> $employeeIds */
    private function task(User $actor, TaskAssignmentMode $mode, array $employeeIds = [], ?int $teamId = null, $dueAt = null): Task
    {
        return app(TaskService::class)->create(new CreateTaskData(
            title: 'Notification Task '.Str::random(5), description: null, priority: TaskPriority::Normal,
            assignedEmployeeId: null, assignedTeamId: $teamId, dueAt: $dueAt, followUpAt: null,
            linkedType: null, linkedRecordId: null, idempotencyKey: (string) Str::uuid(),
            assignedEmployeeIds: $employeeIds, assignmentMode: $mode,
        ), $actor);
    }

    private function user(EmployeeRole $role, string $name, ?Team $team = null): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name, 'email' => $user->email, 'status' => true, 'team_id' => $team?->id,
        ]);

        return $user->refresh();
    }
}
