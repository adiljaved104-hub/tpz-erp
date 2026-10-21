<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\LeavePolicySettings;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\Hr\JoiningDateLeaveBalanceService;
use App\Services\Hr\LeavePolicyVersioningService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class JoiningAnniversaryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_the_approved_12_day_anniversary_policy_without_carry_forward_or_monthly_accrual(): void
    {
        $policy = LeavePolicy::query()->where('status', true)->whereNull('effective_to')
            ->where('leave_year_basis', 'joining_anniversary')->latest('effective_from')->firstOrFail();

        $this->assertSame('12.00', (string) $policy->annual_leave_entitlement_days);
        $this->assertSame('joining_anniversary', $policy->leave_year_basis);
        $this->assertFalse($policy->monthly_accrual_enabled);
        $this->assertFalse($policy->carry_forward_enabled);
        $this->assertSame('0.00', (string) $policy->maximum_carry_forward_days);
        $this->assertSame('next_anniversary', $policy->cycle_transition_decision);
        $this->assertDatabaseHas('leave_policy_events', ['leave_policy_id' => $policy->id, 'event_type' => 'initial_anniversary_policy']);
    }

    public function test_admin_can_create_a_future_15_day_version_with_explicit_carry_forward_settings(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $future = now()->addMonths(3)->toDateString();

        $policy = app(LeavePolicyVersioningService::class)->createVersion([
            'annual_entitlement_days' => '15', 'carry_forward_enabled' => true,
            'maximum_carry_forward_days' => '4', 'carry_forward_expiry_months' => 6,
            'effective_date' => $future, 'description' => 'Approved future annual leave policy',
            'reason' => 'Owner-approved policy update', 'cycle_transition_decision' => null,
            'status' => 'active', 'activation_confirmed' => true,
        ], $admin);

        $this->assertSame('15.00', (string) $policy->annual_leave_entitlement_days);
        $this->assertTrue($policy->carry_forward_enabled);
        $this->assertSame('4.00', (string) $policy->maximum_carry_forward_days);
        $this->assertSame(6, $policy->carry_forward_expiry_months);
        $this->assertSame('joining_anniversary', $policy->leave_year_basis);
        $this->assertDatabaseHas('leave_policy_events', ['leave_policy_id' => $policy->id, 'event_type' => 'version_created']);
    }

    public function test_mid_cycle_change_requires_an_explicit_transition_decision_and_does_not_close_old_version(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $employee = Employee::factory()->create(['joining_date' => '2025-06-01']);
        $employee->forceFill(['joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id])->save();
        $current = LeavePolicy::query()->whereNull('effective_to')->latest('effective_from')->firstOrFail();

        try {
            app(LeavePolicyVersioningService::class)->createVersion([
                'annual_entitlement_days' => '15', 'carry_forward_enabled' => false,
                'effective_date' => '2026-10-21', 'description' => 'Future policy',
                'reason' => 'Owner-approved test version', 'cycle_transition_decision' => null,
            ], $owner);
            $this->fail('A mid-cycle policy change was created without an explicit transition decision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle_transition_decision', $exception->errors());
        }

        $this->assertNull($current->fresh()->effective_to);
        $this->assertSame(2, LeavePolicy::query()->count());
        $this->assertNull($current->fresh()->effective_to);
    }

    public function test_next_anniversary_transition_preserves_current_cycle_and_applies_new_entitlement_prospectively(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $employeeUser = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($employeeUser)->role(EmployeeRole::Staff)->create([
            'email' => $employeeUser->email, 'status' => true, 'joining_date' => '2025-01-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $admin->id,
        ]);
        $effective = now()->addDay()->toDateString();

        $newPolicy = app(LeavePolicyVersioningService::class)->createVersion([
            'annual_entitlement_days' => '15', 'carry_forward_enabled' => false,
            'effective_date' => $effective, 'description' => 'Future annual entitlement',
            'reason' => 'Approved prospective policy change', 'cycle_transition_decision' => 'next_anniversary',
            'status' => 'active', 'activation_confirmed' => true,
        ], $admin);

        $balances = app(JoiningDateLeaveBalanceService::class);
        $historicalCycle = $balances->forDate($employee, '2025-06-01');
        $currentCycle = $balances->forDate($employee, now()->toDateString());
        $nextCycle = $balances->forDate($employee, '2027-01-01');
        $this->assertSame('12.00', $historicalCycle->entitlement, 'A future policy version must not rewrite a completed historical cycle.');
        $this->assertSame('12.00', $currentCycle->entitlement);
        $this->assertSame('15.00', $nextCycle->entitlement);
        $this->assertSame('0.00', $currentCycle->carryForward);
        $event = $newPolicy->events()->where('event_type', 'version_created')->firstOrFail();
        $this->assertSame($effective, $event->effective_date->toDateString());
        $this->assertSame('Approved prospective policy change', $event->reason);
    }

    public function test_future_carry_forward_is_capped_and_expires_without_backfilling_old_cycles(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $employeeUser = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($employeeUser)->role(EmployeeRole::Staff)->create([
            'email' => $employeeUser->email, 'status' => true, 'joining_date' => '2025-01-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $admin->id,
        ]);

        app(LeavePolicyVersioningService::class)->createVersion([
            'annual_entitlement_days' => '15', 'carry_forward_enabled' => true,
            'maximum_carry_forward_days' => '4', 'carry_forward_expiry_months' => 6,
            'effective_date' => now()->addDay()->toDateString(), 'description' => 'Future carry-forward policy',
            'reason' => 'Approved controlled carry-forward', 'cycle_transition_decision' => 'apply_full_entitlement',
            'status' => 'active', 'activation_confirmed' => true,
        ], $admin);

        $balances = app(JoiningDateLeaveBalanceService::class);
        $this->assertSame('0.00', $balances->forDate($employee, '2026-10-21')->carryForward);
        $this->assertSame('4.00', $balances->forDate($employee, '2027-06-30')->carryForward);
        $this->assertSame('0.00', $balances->forDate($employee, '2027-07-01')->carryForward);
    }

    public function test_owner_admin_policy_access_respects_an_explicit_admin_deny(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id, 'permission_key' => HrPermission::LeaveManagePolicy->value,
            'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id, 'reason' => 'Policy management withheld',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);

        $this->actingAs($admin);
        $this->assertFalse(LeavePolicySettings::canAccess());
        try {
            app(LeavePolicyVersioningService::class)->createVersion([
                'annual_entitlement_days' => '15', 'carry_forward_enabled' => false,
                'effective_date' => now()->addMonths(4)->toDateString(), 'description' => 'Denied update', 'reason' => 'Test',
            ], $admin);
            $this->fail('An admin with an explicit deny changed company-wide policy.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(2, LeavePolicy::query()->count());
    }

    public function test_authorized_hr_staff_can_manage_policy_only_after_an_explicit_permission_grant(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $hrStaff = $this->user(EmployeeRole::Staff);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $hrStaff->employee->id, 'permission_key' => HrPermission::LeaveManagePolicy->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $admin->id,
            'reason' => 'Authorized HR policy administration',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($hrStaff->employee->id);

        $this->actingAs($hrStaff);
        $this->assertTrue(LeavePolicySettings::canAccess());
        $draft = app(LeavePolicyVersioningService::class)->createVersion([
            'annual_entitlement_days' => '12', 'carry_forward_enabled' => false,
            'effective_date' => now()->addMonths(2)->toDateString(), 'description' => 'HR reviewed 12-day policy draft',
            'reason' => 'Preparing a future policy version', 'cycle_transition_decision' => 'apply_full_entitlement',
            'status' => 'draft', 'activation_confirmed' => false,
        ], $hrStaff);

        $this->assertFalse($draft->status);
        $this->assertSame(3, LeavePolicy::query()->count());
    }

    public function test_draft_does_not_close_or_change_the_applicable_policy_or_employee_balance(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $employee = $this->verifiedEmployee($admin, '2025-01-01');
        $current = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->firstOrFail();
        $effective = now()->addMonth()->toDateString();

        $draft = app(LeavePolicyVersioningService::class)->createVersion([
            'annual_entitlement_days' => '20', 'carry_forward_enabled' => false,
            'effective_date' => $effective, 'description' => 'Draft entitlement proposal',
            'reason' => 'Pending review', 'cycle_transition_decision' => 'apply_full_entitlement',
            'status' => 'draft', 'activation_confirmed' => false,
        ], $admin);

        $this->assertFalse($draft->status);
        $this->assertNull($current->fresh()->effective_to);
        $this->assertOneApplicablePolicy(now()->toDateString());
        $this->assertOneApplicablePolicy($effective);
        $this->assertSame('12.00', app(JoiningDateLeaveBalanceService::class)->forDate($employee, $effective)->entitlement);
        $this->assertDatabaseHas('leave_policy_events', ['leave_policy_id' => $draft->id, 'event_type' => 'draft_created']);
    }

    public function test_policy_settings_exposes_only_active_and_draft_statuses_with_explicit_activation_guidance(): void
    {
        $admin = $this->user(EmployeeRole::Admin);

        Livewire::actingAs($admin)->test(LeavePolicySettings::class)
            ->assertSee('Policy status')
            ->assertSee('Draft — does not affect balances')
            ->assertSee('Active — schedule as replacement on effective date')
            ->assertSee('Save Draft')
            ->assertSee('I confirm activating a selected Draft replacement')
            ->assertDontSee('Inactive');
    }

    public function test_active_replacement_requires_confirmation_and_applies_only_from_its_effective_date(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $employee = $this->verifiedEmployee($admin, '2025-01-01');
        $current = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->firstOrFail();
        $effective = now()->addMonth()->toDateString();
        $service = app(LeavePolicyVersioningService::class);
        $data = [
            'annual_entitlement_days' => '18', 'carry_forward_enabled' => false,
            'effective_date' => $effective, 'description' => 'Future replacement policy',
            'reason' => 'Approved effective-dated replacement', 'cycle_transition_decision' => 'apply_full_entitlement',
            'status' => 'active', 'activation_confirmed' => false,
        ];

        try {
            $service->createVersion($data, $admin);
            $this->fail('An active replacement was accepted without explicit confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activation_confirmed', $exception->errors());
        }
        $this->assertNull($current->fresh()->effective_to);
        $this->assertSame('12.00', app(JoiningDateLeaveBalanceService::class)->forDate($employee, $effective)->entitlement);

        $data['activation_confirmed'] = true;
        $replacement = $service->createVersion($data, $admin);

        $this->assertTrue($replacement->status);
        $this->assertSame($effective, $current->fresh()->effective_to->addDay()->toDateString());
        $this->assertOneApplicablePolicy(CarbonImmutable::parse($effective)->subDay()->toDateString());
        $this->assertOneApplicablePolicy($effective);
        $this->assertSame('12.00', app(JoiningDateLeaveBalanceService::class)->forDate($employee, CarbonImmutable::parse($effective)->subDay())->entitlement);
        $this->assertSame('18.00', app(JoiningDateLeaveBalanceService::class)->forDate($employee, $effective)->entitlement);
        $this->assertSame('12.00', app(JoiningDateLeaveBalanceService::class)->forDate($employee, CarbonImmutable::parse($effective)->subDay())->entitlement);
    }

    public function test_draft_activation_requires_confirmation_and_permission_and_preserves_continuity(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $employee = $this->verifiedEmployee($admin, '2025-01-01');
        $current = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->firstOrFail();
        $effective = now()->addMonth()->toDateString();
        $draft = app(LeavePolicyVersioningService::class)->createVersion([
            'annual_entitlement_days' => '17', 'carry_forward_enabled' => false,
            'effective_date' => $effective, 'description' => 'Draft ready for activation',
            'reason' => 'Approved policy proposal', 'cycle_transition_decision' => 'apply_full_entitlement',
            'status' => 'draft', 'activation_confirmed' => false,
        ], $admin);
        $service = app(LeavePolicyVersioningService::class);

        try {
            $service->activateDraft($draft->id, $admin, false);
            $this->fail('A Draft was activated without confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activation_confirmed', $exception->errors());
        }
        $this->assertNull($current->fresh()->effective_to);
        $this->assertFalse($draft->fresh()->status);

        $staff = $this->user(EmployeeRole::Staff);
        $this->expectException(AuthorizationException::class);
        $service->activateDraft($draft->id, $staff, true);
    }

    public function test_confirmed_draft_activation_keeps_old_policy_for_prior_dates_and_logs_replacement(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $employee = $this->verifiedEmployee($admin, '2025-01-01');
        $effective = now()->addMonth()->toDateString();
        $current = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->firstOrFail();
        $draft = app(LeavePolicyVersioningService::class)->createVersion([
            'annual_entitlement_days' => '19', 'carry_forward_enabled' => false,
            'effective_date' => $effective, 'description' => 'Approved Draft',
            'reason' => 'Prospective entitlement change', 'cycle_transition_decision' => 'apply_full_entitlement',
            'status' => 'draft', 'activation_confirmed' => false,
        ], $admin);

        $active = app(LeavePolicyVersioningService::class)->activateDraft($draft->id, $admin, true);

        $this->assertTrue($active->status);
        $this->assertTrue(LeavePolicy::query()->findOrFail($draft->id)->status);
        $this->assertSame('12.00', app(JoiningDateLeaveBalanceService::class)->forDate($employee, CarbonImmutable::parse($effective)->subDay())->entitlement);
        $this->assertSame('19.00', app(JoiningDateLeaveBalanceService::class)->forDate($employee, $effective)->entitlement);
        $this->assertOneApplicablePolicy(CarbonImmutable::parse($effective)->subDay()->toDateString());
        $this->assertOneApplicablePolicy($effective);
        $this->assertDatabaseHas('leave_policy_events', ['leave_policy_id' => $draft->id, 'event_type' => 'draft_activated']);
        $this->assertSame(CarbonImmutable::parse($effective)->subDay()->toDateString(), $current->fresh()->effective_to->toDateString());
    }

    private function verifiedEmployee(User $verifier, string $joiningDate): Employee
    {
        $employeeUser = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($employeeUser)->role(EmployeeRole::Staff)->create([
            'email' => $employeeUser->email, 'status' => true, 'joining_date' => $joiningDate,
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $verifier->id,
        ]);

        return $employee;
    }

    private function assertOneApplicablePolicy(string $date): void
    {
        $count = LeavePolicy::query()->where('status', true)->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->count();

        $this->assertSame(1, $count, "Expected exactly one active leave policy for {$date}.");
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
