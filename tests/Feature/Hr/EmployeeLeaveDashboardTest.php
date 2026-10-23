<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\EmployeeLeaveDashboard;
use App\Filament\Pages\Hr\LeavePolicySettings;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\HistoricalLeaveActivationService;
use App\Services\Hr\HistoricalLeaveService;
use App\Services\Hr\JoiningDateCorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeLeaveDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_activate_one_reviewed_employee_from_dashboard_without_unlocking_other_employees(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Reviewed Colleague');
        $other = $this->user(EmployeeRole::Staff, 'Unreviewed Colleague');
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($staff->employee, $owner);

        Livewire::actingAs($owner)->test(EmployeeLeaveDashboard::class)
            ->call('selectEmployee', $staff->employee->id)
            ->assertSee('Ready for Activation')
            ->call('activateSelectedEmployee')
            ->assertSee('Active');

        $this->assertTrue($service->isActiveFor($staff->employee));
        $this->assertSame('Pending HR Review', $service->employeeStatus($other->employee));
        $this->assertDatabaseCount('employee_annual_leave_activations', 1);
        Livewire::actingAs($owner)->test(LeavePolicySettings::class)
            ->assertSee('Individual employee activation in progress')
            ->assertSee('the shared policy above was not changed');
        Livewire::actingAs($staff)->test(EmployeeLeaveDashboard::class)->assertForbidden();
    }

    public function test_owner_sees_active_inactive_and_unverified_employees_without_trusting_unverified_balances(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $active = $this->user(EmployeeRole::Staff, 'Active Colleague');
        $inactive = $this->user(EmployeeRole::Staff, 'Inactive Colleague', active: false, verified: false);
        $inactive->employee->forceFill(['joining_date' => null])->save();
        $admin = $this->user(EmployeeRole::Admin, 'Admin');

        Livewire::actingAs($owner)->test(EmployeeLeaveDashboard::class)
            ->assertSee('Active Colleague')
            ->assertSee('Inactive Colleague')
            ->set('employmentFilter', 'inactive')
            ->assertSee('Inactive Colleague')
            ->assertDontSee('Active Colleague')
            ->set('verificationFilter', 'unverified')
            ->call('selectEmployee', $inactive->employee->id)
            ->assertSee('Not Verified')
            ->assertSee('Not yet verified')
            ->assertSee('The employee joining date must be verified')
            ->assertDontSee('Annual Leave Enabled');

        Livewire::actingAs($admin)->test(EmployeeLeaveDashboard::class)
            ->assertSee('Inactive Colleague');

        $this->actingAs($owner)->withoutVite()
            ->get(EmployeeLeaveDashboard::getUrl(['selectedEmployeeId' => $active->employee->id]))->assertOk();
        $this->assertDatabaseCount('historical_leave_workflow_activations', 0);
    }

    public function test_corrections_and_joining_date_audit_remain_visible_without_double_deduction(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22'));
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Corrected Employee');
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $service = app(HistoricalLeaveService::class);
        $input = [
            'employee_id' => $staff->employee->id, 'leave_type_id' => $annual->id,
            'from_date' => '2026-08-03', 'to_date' => '2026-08-03',
            'chargeable_days' => '1.00', 'manual_override_reason' => 'Verified HR paper register',
            'approval_status' => 'approved', 'reason' => 'Previously taken leave',
            'historical_source' => 'Paper register', 'idempotency_key' => fake()->uuid(), 'confirmed' => true,
        ];
        $original = $service->record($input, $owner)->firstOrFail();
        $service->correct($original, [
            ...$input, 'to_date' => '2026-08-04', 'chargeable_days' => '2.00',
            'idempotency_key' => fake()->uuid(),
        ], 'Original end date was wrong', $owner);
        app(JoiningDateCorrectionService::class)->verifyOrCorrect($staff->employee, [
            'joining_date' => '2025-01-01', 'reason' => 'HR reconfirmed the original joining date',
            'confirmed' => true,
        ], $owner);

        Livewire::actingAs($owner)->test(EmployeeLeaveDashboard::class)
            ->call('selectEmployee', $staff->employee->id)
            ->assertSee('Voided')
            ->assertSee('Correction of record #'.$original->id)
            ->assertSee('Original end date was wrong')
            ->assertSee('Joining Date Audit')
            ->assertSee('HR reconfirmed the original joining date')
            ->assertViewHas('balance', fn ($balance): bool => $balance->historicalUsed === '2.00'
                && $balance->used === '2.00' && $balance->availableAfterPending === '10.00');
        $this->assertDatabaseCount('historical_leave_records', 2);
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public function test_owner_dashboard_reuses_anniversary_balance_without_deducting_approved_leave_twice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22'));
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Leave Colleague');
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        app(HistoricalLeaveService::class)->record([
            'employee_id' => $staff->employee->id, 'leave_type_id' => $annual->id,
            'from_date' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'to_date' => now()->startOfMonth()->subMonths(2)->addDay()->toDateString(),
            'chargeable_days' => '2.00', 'manual_override_reason' => 'Verified HR paper register',
            'approval_status' => 'approved', 'reason' => 'Previously taken annual leave',
            'historical_source' => 'Paper register', 'idempotency_key' => fake()->uuid(), 'confirmed' => true,
        ], $owner);
        $this->request($staff->employee, $annual, $owner, 'approved', now()->startOfMonth()->subMonth()->toDateString());
        $this->request($staff->employee, $annual, $owner, 'pending', now()->addWeek()->toDateString());

        Livewire::actingAs($owner)->test(EmployeeLeaveDashboard::class)
            ->call('selectEmployee', $staff->employee->id)
            ->assertSee('Previously taken annual leave')
            ->assertSee('ERP Leave Requests')
            ->assertSee('Annual days')
            ->assertSee('Approved days used')
            ->assertSee('Pending days')
            ->assertSee('Days left after pending')
            ->assertViewHas('balance', fn ($balance): bool => $balance->entitlement === '12.00'
                && $balance->historicalUsed === '2.00' && $balance->currentUsed === '1.00'
                && $balance->used === '3.00' && $balance->pending === '1.00'
                && $balance->remaining === '9.00' && $balance->availableAfterPending === '8.00')
            ->call('downloadEmployeeExcel', $staff->employee->id)
            ->assertFileDownloaded('employee_leave_'.$staff->employee->employee_id.'.xlsx');

        $this->assertDatabaseCount('historical_leave_workflow_activations', 0);
    }

    public function test_restricted_hr_user_cannot_view_or_export_an_out_of_scope_employee(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $hr = $this->user(EmployeeRole::Staff, 'Team HR');
        $team = Team::query()->create(['name' => 'Dashboard Team', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other Dashboard Team', 'status' => true]);
        $hr->employee->forceFill(['team_id' => $team->id])->save();
        $colleague = $this->user(EmployeeRole::Staff, 'Visible Colleague', active: false);
        $colleague->employee->forceFill(['team_id' => $team->id])->save();
        $outsider = $this->user(EmployeeRole::Staff, 'Private Colleague');
        $outsider->employee->forceFill(['team_id' => $otherTeam->id])->save();
        foreach ([HrPermission::LeaveHistoricalView, HrPermission::LeaveViewTeam] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $hr->employee->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
                'reason' => 'Scoped HR dashboard test',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($hr->employee->id);

        Livewire::actingAs($hr)->test(EmployeeLeaveDashboard::class)
            ->assertSee('Visible Colleague')
            ->assertDontSee('Private Colleague')
            ->call('selectEmployee', $colleague->employee->id)
            ->assertSee('Visible Colleague');
        Livewire::actingAs($hr)->test(EmployeeLeaveDashboard::class)
            ->call('selectEmployee', $outsider->employee->id)->assertForbidden();
        Livewire::actingAs($hr)->test(EmployeeLeaveDashboard::class)
            ->call('downloadEmployeeExcel', $outsider->employee->id)->assertForbidden();
        $this->actingAs($hr)->withoutVite()
            ->get(EmployeeLeaveDashboard::getUrl(['selectedEmployeeId' => $outsider->employee->id]))->assertForbidden();
    }

    public function test_regular_employee_and_technician_cannot_open_the_dashboard(): void
    {
        foreach ([EmployeeRole::Staff, EmployeeRole::Technician] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->withoutVite()->get(EmployeeLeaveDashboard::getUrl())->assertForbidden();
        }
    }

    private function request(Employee $employee, LeaveType $type, User $owner, string $status, string $date): void
    {
        $request = LeaveRequest::query()->create([
            'reference' => 'DASH-'.fake()->unique()->numerify('######'), 'employee_id' => $employee->id,
            'leave_type_id' => $type->id, 'leave_policy_id' => LeavePolicy::query()->value('id'),
            'from_date' => $date, 'to_date' => $date, 'requested_working_days' => '1.00',
            'status' => $status, 'reason' => 'ERP annual leave request', 'submitted_at' => now(),
            'decided_by_user_id' => $status === 'approved' ? $owner->id : null,
            'decided_at' => $status === 'approved' ? now() : null,
            'idempotency_key' => fake()->uuid(),
        ]);
        LeaveRequestDay::query()->create([
            'leave_request_id' => $request->id, 'leave_date' => $date,
            'classification' => 'working_leave', 'counts_as_leave' => true, 'leave_units' => '1.00',
        ]);
    }

    private function user(EmployeeRole $role, ?string $name = null, bool $active = true, bool $verified = true): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name ?? fake()->name(), 'email' => $user->email, 'status' => $active,
            'joining_date' => '2025-01-01', 'joining_date_verified_at' => $verified ? now() : null,
            'joining_date_verified_by_user_id' => $verified ? $user->id : null,
        ]);

        return $user->refresh();
    }
}
