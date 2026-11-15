<?php

namespace Tests\Feature\Mobile;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hr\HistoricalLeaveActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileLeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $actor = User::factory()->create();
        DB::table('historical_leave_workflow_activations')->insert([
            'workflow' => 'annual_leave',
            'approved_by_user_id' => $actor->id,
            'reviewed_employee_count' => 0,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_employee_can_submit_and_cancel_own_pending_leave_through_domain_service(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $staff = $this->user(EmployeeRole::Staff, $team, $owner);
        $this->configureSchedule($staff->employee, $owner);

        $this->as($staff)->getJson('/api/mobile/v1/workspace/hr')
            ->assertOk()
            ->assertJsonPath('data.can_request_leave', true);

        $options = $this->as($staff)->getJson('/api/mobile/v1/workspace/hr/leave-options')
            ->assertOk()
            ->assertJsonMissing(['code' => 'compensatory_off']);
        $annual = collect($options->json('data.leave_types'))->firstWhere('code', 'annual');
        $this->assertNotNull($annual);

        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'leave_type_id' => $annual['id'],
            'from_date' => '2026-09-07',
            'to_date' => '2026-09-08',
            'reason' => 'Mobile leave workflow coverage',
            'idempotency_key' => $idempotencyKey,
        ];
        $created = $this->as($staff)->postJson('/api/mobile/v1/workspace/hr/leave-requests', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.fields.working_days', '2.00')
            ->assertJsonFragment(['key' => 'cancel'])
            ->assertJsonMissing(['key' => 'approve']);

        $leaveId = $created->json('data.id');
        $this->assertDatabaseHas('leave_requests', ['id' => $leaveId, 'idempotency_key' => $idempotencyKey]);
        $this->as($staff)->postJson('/api/mobile/v1/workspace/hr/leave-requests', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $leaveId);
        $this->assertDatabaseCount('leave_requests', 1);

        $this->as($staff)->postJson("/api/mobile/v1/workspace/hr/leave-requests/{$leaveId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonMissing(['key' => 'cancel']);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveId,
            'employee_id' => $staff->employee->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_approval_and_rejection_actions_preserve_team_scope_and_permission_overrides(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $manager = $this->user(EmployeeRole::Manager, $team, $owner);
        $staff = $this->user(EmployeeRole::Staff, $team, $owner);
        $outsider = $this->user(EmployeeRole::Staff, $otherTeam, $owner);
        $this->configureSchedule($staff->employee, $owner);

        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id,
            'permission_key' => HrPermission::LeaveApprove->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Delegated mobile leave approval',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);

        $annualId = $this->as($staff)->getJson('/api/mobile/v1/workspace/hr/leave-options')
            ->json('data.leave_types.0.id');
        $created = $this->as($staff)->postJson('/api/mobile/v1/workspace/hr/leave-requests', [
            'leave_type_id' => $annualId,
            'from_date' => '2026-09-09',
            'to_date' => '2026-09-09',
            'reason' => 'Scoped manager approval',
        ])->assertOk();
        $leaveId = $created->json('data.id');

        $this->as($manager)->getJson("/api/mobile/v1/workspace/hr/leave-requests/{$leaveId}")
            ->assertOk()
            ->assertJsonFragment(['key' => 'approve'])
            ->assertJsonFragment(['key' => 'reject'])
            ->assertJsonMissing(['key' => 'cancel']);
        $this->as($outsider)->getJson("/api/mobile/v1/workspace/hr/leave-requests/{$leaveId}")
            ->assertNotFound();
        $this->as($outsider)->postJson("/api/mobile/v1/workspace/hr/leave-requests/{$leaveId}/approve")
            ->assertNotFound();

        $this->as($manager)->postJson("/api/mobile/v1/workspace/hr/leave-requests/{$leaveId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $second = $this->as($staff)->postJson('/api/mobile/v1/workspace/hr/leave-requests', [
            'leave_type_id' => $annualId,
            'from_date' => '2026-09-10',
            'to_date' => '2026-09-10',
            'reason' => 'Scoped manager rejection',
        ])->assertOk();
        $secondId = $second->json('data.id');
        $this->as($manager)->postJson("/api/mobile/v1/workspace/hr/leave-requests/{$secondId}/reject", [
            'reason' => 'Operational coverage is unavailable',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.fields.decision_reason', 'Operational coverage is unavailable');
    }

    public function test_invalid_or_unauthorized_leave_actions_fail_safely(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $staff = $this->user(EmployeeRole::Staff, $team, $owner);
        $outsider = $this->user(EmployeeRole::Staff, $team, $owner);
        $this->configureSchedule($staff->employee, $owner);

        $annualId = $this->as($staff)->getJson('/api/mobile/v1/workspace/hr/leave-options')
            ->json('data.leave_types.0.id');
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'leave_type_id' => $annualId,
            'from_date' => '2026-09-11',
            'to_date' => '2026-09-11',
            'reason' => 'Authorization coverage',
            'idempotency_key' => $idempotencyKey,
        ];
        $leaveId = $this->as($staff)->postJson('/api/mobile/v1/workspace/hr/leave-requests', $payload)
            ->assertOk()->json('data.id');

        $this->as($outsider)->postJson('/api/mobile/v1/workspace/hr/leave-requests', $payload)
            ->assertForbidden();
        $this->as($staff)->postJson('/api/mobile/v1/workspace/hr/leave-requests', [
            ...$payload,
            'idempotency_key' => 'not-a-uuid',
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('leave_requests', 1);

        $this->as($staff)->postJson("/api/mobile/v1/workspace/hr/leave-requests/{$leaveId}/approve")
            ->assertForbidden();
        $this->as($owner)->postJson("/api/mobile/v1/workspace/hr/leave-requests/{$leaveId}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile leave test')->plainTextToken);
    }

    private function user(EmployeeRole $role, Team $team, ?User $reviewer = null): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'team_id' => $team->id,
            'status' => true,
            'joining_date' => '2025-01-01',
        ]);
        $employee->forceFill([
            'joining_date_verified_at' => now(),
            'joining_date_verified_by_user_id' => ($reviewer ?? $user)->id,
        ])->save();
        app(HistoricalLeaveActivationService::class)->review($employee, $reviewer ?? $user);

        return $user->refresh();
    }

    private function configureSchedule(Employee $employee, User $actor): void
    {
        $schedule = WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail();
        foreach (range(0, 6) as $weekday) {
            WorkScheduleDay::query()->firstOrCreate([
                'work_schedule_id' => $schedule->id,
                'cycle_week' => 1,
                'weekday' => $weekday,
            ], [
                'is_working_day' => ! in_array($weekday, [0, 6], true),
                'expected_start_time' => in_array($weekday, [0, 6], true) ? null : '09:00:00',
                'expected_end_time' => in_array($weekday, [0, 6], true) ? null : '17:00:00',
                'earns_compensatory_off' => false,
            ]);
        }
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id,
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'assigned_by_user_id' => $actor->id,
            'reason' => 'Mobile leave test schedule',
        ]);
    }
}
