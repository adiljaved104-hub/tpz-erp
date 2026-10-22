<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hr\HistoricalLeaveActivationService;
use App\Services\Hr\HrScopeService;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $fixtureActor = User::factory()->create();
        DB::table('historical_leave_workflow_activations')->insert([
            'workflow' => 'annual_leave', 'approved_by_user_id' => $fixtureActor->id,
            'reviewed_employee_count' => 0, 'activated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_staff_sees_own_leave_and_annual_balance_tracks_pending_approved_and_rejected(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $service = app(LeaveService::class);

        $pending = $service->submit(['leave_type_id' => $annual->id, 'from_date' => '2026-09-07', 'to_date' => '2026-09-08', 'reason' => 'Personal leave'], $staff);
        $balance = app(LeaveBalanceService::class)->forDate($staff->employee, '2026-09-07');
        $this->assertSame('12.00', $balance->entitlement);
        $this->assertSame('2.00', $balance->pending);
        $this->assertSame('12.00', $balance->remaining);
        $this->assertSame('10.00', $balance->availableAfterPending);
        $this->assertEquals([$pending->id], app(HrScopeService::class)->leaveQuery($staff)->pluck('id')->all());
        $this->actingAs($staff)->get('/admin/hr/leave')->assertOk()->assertSee('Request Leave');

        $service->reject($pending, 'Coverage cannot be arranged', $owner);
        $balance = app(LeaveBalanceService::class)->forDate($staff->employee, '2026-09-07');
        $this->assertSame('0.00', $balance->pending);
        $this->assertSame('0.00', $balance->used);

        $approved = $service->submit(['leave_type_id' => $annual->id, 'from_date' => '2026-09-09', 'to_date' => '2026-09-10', 'reason' => 'Family leave'], $staff);
        $service->approve($approved, $owner);
        $balance = app(LeaveBalanceService::class)->forDate($staff->employee, '2026-09-09');
        $this->assertSame('2.00', $balance->used);
        $this->assertSame('10.00', $balance->remaining);
    }

    public function test_comp_off_leave_type_never_consumes_annual_entitlement(): void
    {
        [, $staff] = $this->people();
        $comp = LeaveType::query()->where('code', 'compensatory_off')->firstOrFail();
        $request = LeaveRequest::query()->create([
            'reference' => 'LVR-2026-009999', 'employee_id' => $staff->employee->id,
            'leave_type_id' => $comp->id, 'leave_policy_id' => 1, 'from_date' => '2026-09-07', 'to_date' => '2026-09-07',
            'requested_working_days' => '1.00', 'status' => 'approved', 'reason' => 'Approved Comp Off',
            'submitted_at' => now(), 'decided_by_user_id' => 1, 'decided_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);
        LeaveRequestDay::query()->create(['leave_request_id' => $request->id, 'leave_date' => '2026-09-07', 'classification' => 'working_leave', 'counts_as_leave' => true, 'leave_units' => '1.00']);

        $this->assertSame('0.00', app(LeaveBalanceService::class)->forDate($staff->employee, '2026-09-07')->used);
    }

    public function test_employee_cannot_self_approve_and_manager_needs_override_and_team_scope(): void
    {
        [$owner, $staff, $manager] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $request = app(LeaveService::class)->submit([
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'reason' => 'Personal leave',
        ], $staff);

        try {
            app(LeaveService::class)->approve($request, $staff);
            $this->fail('Self approval was allowed.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertFalse(app(HrScopeService::class)->canApprove($manager, $request));
        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id, 'permission_key' => HrPermission::LeaveApprove->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Delegated leave approval',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $this->assertTrue(app(HrScopeService::class)->canApprove($manager, $request));
    }

    public function test_submit_approve_and_reject_send_safe_database_notifications(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $request = app(LeaveService::class)->submit(['leave_type_id' => $annual->id, 'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'reason' => 'Personal leave'], $staff);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id, 'type' => 'leave.submitted']);
        app(LeaveService::class)->approve($request, $owner);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $staff->id, 'type' => 'leave.approved']);

        $second = app(LeaveService::class)->submit(['leave_type_id' => $annual->id, 'from_date' => '2026-09-08', 'to_date' => '2026-09-08', 'reason' => 'Personal leave'], $staff);
        app(LeaveService::class)->reject($second, 'Not operationally possible', $owner);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $staff->id, 'type' => 'leave.rejected']);
        $payload = json_encode(DB::table('notifications')->pluck('data'));
        $this->assertStringNotContainsString('Personal leave', $payload);
    }

    public function test_request_fails_clearly_when_schedule_days_are_not_configured(): void
    {
        [, $staff] = $this->people();
        $this->expectException(ValidationException::class);
        app(LeaveService::class)->submit([
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'reason' => 'Personal leave',
        ], $staff);
    }

    public function test_annual_leave_is_rejected_until_joining_date_is_verified(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $staff->employee->forceFill(['joining_date_verified_at' => null, 'joining_date_verified_by_user_id' => null])->save();

        try {
            app(LeaveService::class)->submit([
                'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
                'from_date' => '2026-09-07', 'to_date' => '2026-09-07', 'reason' => 'Annual leave before verification',
            ], $staff);
            $this->fail('An anniversary-based annual balance was calculated from an unverified joining date.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('joining_date', $exception->errors());
            $this->assertStringContainsString('joining date must be verified', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(0, LeaveRequest::query()->where('employee_id', $staff->employee->id)->count());
    }

    public function test_leave_page_explains_when_joining_date_verification_is_required(): void
    {
        [, $staff] = $this->people();
        $staff->employee->forceFill(['joining_date_verified_at' => null, 'joining_date_verified_by_user_id' => null])->save();

        $this->actingAs($staff)->get('/admin/hr/leave')->assertOk()
            ->assertSee('The annual balance is unavailable until the joining date is verified.')
            ->assertSee('must be verified before an annual leave balance can be calculated');
    }

    public function test_leave_page_shows_balance_employee_and_status_filters_without_cross_team_disclosure(): void
    {
        [$owner, $staff, $manager] = $this->people();
        $otherTeam = Team::query()->create(['name' => 'Other team', 'status' => true]);
        $outsider = $this->user(EmployeeRole::Staff, $otherTeam);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $policy = LeavePolicy::query()->where('status', true)->firstOrFail();

        $insideRequest = $this->pendingRequest($staff->employee, $annual, $policy, 'LVR-2026-009991');
        $outsideRequest = $this->pendingRequest($outsider->employee, $annual, $policy, 'LVR-2026-009992');

        Livewire::actingAs($manager)->test(LeaveManagement::class)
            ->assertSee('Your Leave Balance')
            ->assertSee('Leave Used')
            ->assertSee('Previous Leave Used')
            ->assertSee('Waiting for Approval')
            ->assertSee('Days Left')
            ->assertSee('Employee')
            ->assertSee('Approval status')
            ->assertSee($insideRequest->reference)
            ->assertDontSee($outsideRequest->reference)
            ->set('employeeFilter', $staff->employee->id)
            ->assertSee($insideRequest->reference)
            ->set('statusFilter', 'approved')
            ->assertDontSee($insideRequest->reference)
            ->call('clearFilters')
            ->assertSee($insideRequest->reference)
            ->assertDontSee($outsideRequest->reference);
    }

    private function pendingRequest(Employee $employee, LeaveType $type, LeavePolicy $policy, string $reference): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'reference' => $reference,
            'employee_id' => $employee->id,
            'team_id_at_request' => $employee->team_id,
            'leave_type_id' => $type->id,
            'leave_policy_id' => $policy->id,
            'from_date' => '2026-09-07',
            'to_date' => '2026-09-07',
            'requested_working_days' => '1.00',
            'status' => 'pending',
            'reason' => 'Test request for scoped filters',
            'submitted_at' => now(),
            'idempotency_key' => fake()->uuid(),
        ]);
    }

    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $manager = $this->user(EmployeeRole::Manager, $team);

        return [$owner, $staff, $manager];
    }

    private function configureSchedule(Employee $employee, User $actor): void
    {
        $schedule = WorkSchedule::query()->where('code', 'PK_OFFICE')->firstOrFail();
        foreach (range(0, 6) as $weekday) {
            WorkScheduleDay::query()->create([
                'work_schedule_id' => $schedule->id, 'cycle_week' => 1, 'weekday' => $weekday,
                'is_working_day' => ! in_array($weekday, [0, 6], true),
                'expected_start_time' => in_array($weekday, [0, 6], true) ? null : '09:00:00',
                'expected_end_time' => in_array($weekday, [0, 6], true) ? null : '17:00:00',
                'earns_compensatory_off' => false,
            ]);
        }
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id, 'employee_id' => $employee->id,
            'effective_from' => '2026-01-01', 'assigned_by_user_id' => $actor->id,
            'reason' => 'Test schedule assignment',
        ]);
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team->id, 'status' => true, 'joining_date' => '2025-01-01',
        ]);
        $employee->forceFill(['joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $user->id])->save();
        $owner = Employee::query()->where('role', EmployeeRole::Owner->value)->first()?->user;
        app(HistoricalLeaveActivationService::class)->review($employee, $owner ?? $user);

        return $user->refresh();
    }
}
