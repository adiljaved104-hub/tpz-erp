<?php

namespace Tests\Feature\Tasks;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeeRole;
use App\Enums\TaskLinkedType;
use App\Enums\TaskPriority;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskResourceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_task_list_renders_for_every_active_role_when_there_are_no_tasks(): void
    {
        foreach ([EmployeeRole::Owner, EmployeeRole::Admin, EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $this->actingAs($this->user($role))
                ->get(TaskResource::getUrl())
                ->assertOk();
        }
    }

    public function test_task_tabs_render_nullable_assignments_and_controlled_linked_records(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager, $team);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $product = Product::factory()->create();

        $unassigned = $this->create($owner, title: 'Unassigned Task');
        $employeeAssigned = $this->create($owner, employeeId: $staff->employee->id, title: 'Employee Task');
        $teamAssigned = $this->create($owner, teamId: $team->id, title: 'Team Task');
        $linked = $this->create(
            $owner,
            title: 'Linked Product Task',
            linkedType: TaskLinkedType::Product,
            linkedRecordId: $product->id,
        );

        $this->actingAs($owner)
            ->get(TaskResource::getUrl(parameters: ['tab' => 'all']))
            ->assertOk()
            ->assertSee($unassigned->reference)
            ->assertSee($employeeAssigned->reference)
            ->assertSee($teamAssigned->reference)
            ->assertSee($linked->reference);

        $this->actingAs($manager)
            ->get(TaskResource::getUrl(parameters: ['tab' => 'team_tasks']))
            ->assertOk()
            ->assertSee($employeeAssigned->reference)
            ->assertSee($teamAssigned->reference)
            ->assertDontSee($unassigned->reference);

        $this->actingAs($staff)
            ->get(TaskResource::getUrl())
            ->assertOk()
            ->assertSee($employeeAssigned->reference)
            ->assertDontSee($teamAssigned->reference)
            ->assertDontSee($linked->reference);
    }

    private function user(EmployeeRole $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'status' => true,
            'team_id' => $team?->id,
        ]);

        return $user->refresh();
    }

    private function create(
        User $actor,
        ?int $employeeId = null,
        ?int $teamId = null,
        string $title = 'Task',
        ?TaskLinkedType $linkedType = null,
        ?int $linkedRecordId = null,
    ): Task {
        return app(TaskService::class)->create(new CreateTaskData(
            title: $title,
            description: null,
            priority: TaskPriority::Normal,
            assignedEmployeeId: $employeeId,
            assignedTeamId: $teamId,
            dueAt: null,
            followUpAt: null,
            linkedType: $linkedType,
            linkedRecordId: $linkedRecordId,
            idempotencyKey: (string) Str::uuid(),
        ), $actor);
    }
}
