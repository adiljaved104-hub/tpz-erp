<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\VerifyEmployeeJoiningDate;
use App\Models\Employee;
use App\Models\EmployeeJoiningDateAudit;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Hr\JoiningDateCorrectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class JoiningDateCorrectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_preview_and_verify_joining_date_with_balance_impact_audit_without_rewriting_leave(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $employee = $staff->employee;
        $policy = LeavePolicy::query()->whereNull('effective_to')->latest('effective_from')->firstOrFail();
        $leave = LeaveRequest::query()->create([
            'reference' => 'LVR-VERIFY-000001', 'employee_id' => $employee->id,
            'team_id_at_request' => $employee->team_id, 'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'leave_policy_id' => $policy->id, 'from_date' => '2025-06-02', 'to_date' => '2025-06-02',
            'requested_working_days' => '1.00', 'status' => 'pending', 'reason' => 'Existing request must remain unchanged',
            'submitted_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);

        $service = app(JoiningDateCorrectionService::class);
        $preview = $service->preview($employee, '2025-03-15', $owner);
        $this->assertNull($preview['old_current_period']);
        $this->assertSame('2026-03-15', $preview['new_current_period']['start']);
        $this->assertSame('2027-03-14', $preview['new_current_period']['end']);
        $this->assertSame('2027-03-15', $preview['new_current_period']['next_anniversary']);
        $this->assertSame('12.00', $preview['new_current_balance']['entitlement']);
        $this->assertSame('0.00', $preview['new_current_balance']['pending']);
        $this->assertSame('0.00', $preview['new_current_balance']['used']);
        $this->assertSame('12.00', $preview['new_current_balance']['remaining']);
        $this->assertSame('12.00', $preview['new_current_balance']['available_after_pending']);
        $this->assertTrue($preview['existing_leave_records_will_not_be_rewritten']);

        $service->verifyOrCorrect($employee, [
            'joining_date' => '2025-03-15', 'reason' => 'Verified against signed employment record', 'confirmed' => true,
        ], $owner);

        $this->assertSame('2025-03-15', $employee->fresh()->joining_date->toDateString());
        $this->assertNotNull($employee->fresh()->joining_date_verified_at);
        $this->assertDatabaseHas('employee_joining_date_audits', [
            'employee_id' => $employee->id, 'previous_joining_date' => null,
            'actor_user_id' => $owner->id,
            'action' => 'verified', 'reason' => 'Verified against signed employment record',
        ]);
        $this->assertSame('2025-03-15', EmployeeJoiningDateAudit::query()->where('employee_id', $employee->id)->firstOrFail()->corrected_joining_date->toDateString());
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame(1, EmployeeJoiningDateAudit::query()->where('employee_id', $employee->id)->count());

        $service->verifyOrCorrect($employee->fresh(), [
            'joining_date' => '2025-03-14', 'reason' => 'Corrected against the original signed contract', 'confirmed' => true,
        ], $owner);

        $audits = EmployeeJoiningDateAudit::query()->where('employee_id', $employee->id)->orderBy('id')->get();
        $this->assertSame('2025-03-14', $employee->fresh()->joining_date->toDateString());
        $this->assertCount(2, $audits);
        $this->assertSame('2025-03-15', $audits[1]->previous_joining_date->toDateString());
        $this->assertSame('2025-03-14', $audits[1]->corrected_joining_date->toDateString());
        $this->assertSame('corrected', $audits[1]->action);
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame('2025-06-02', $leave->fresh()->from_date->toDateString());
    }

    public function test_verified_joining_date_page_shows_balance_and_next_anniversary_without_rewriting_leave(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);

        Livewire::actingAs($owner)->test(VerifyEmployeeJoiningDate::class, [
            'employee' => $staff->employee,
        ])
            ->set('joiningDate', '2025-03-15')
            ->set('reason', 'Confirmed against original employee file')
            ->set('confirmed', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Annual entitlement')
            ->assertSee('Used leave')
            ->assertSee('Remaining after approved leave')
            ->assertSee('Next anniversary: 2027-03-15')
            ->assertSee('No existing leave record will be edited.');

        $this->assertSame(1, EmployeeJoiningDateAudit::query()->where('employee_id', $staff->employee->id)->count());
    }

    public function test_hr_staff_with_explicit_joining_date_permission_can_use_the_verification_workflow(): void
    {
        $granter = $this->user(EmployeeRole::Admin);
        $hrStaff = $this->user(EmployeeRole::Staff);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        EmployeePermissionOverride::query()->create([
            'employee_id' => $hrStaff->employee->id,
            'permission_key' => HrPermission::LeaveJoiningDateCorrect->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $granter->id,
            'reason' => 'Authorized HR joining-date verification',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($hrStaff->employee->id);

        $this->actingAs($hrStaff);
        $this->assertTrue(VerifyEmployeeJoiningDate::canAccess());
        app(JoiningDateCorrectionService::class)->verifyOrCorrect($employee, [
            'joining_date' => '2025-02-28', 'reason' => 'Verified against signed HR file', 'confirmed' => true,
        ], $hrStaff);

        $this->assertSame('2025-02-28', $employee->fresh()->joining_date->toDateString());
        $this->assertDatabaseHas('employee_joining_date_audits', [
            'employee_id' => $employee->id, 'actor_user_id' => $hrStaff->id, 'action' => 'verified',
            'reason' => 'Verified against signed HR file',
        ]);
    }

    public function test_staff_without_explicit_joining_date_permission_is_denied_from_page_and_service(): void
    {
        $hrStaff = $this->user(EmployeeRole::Staff);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $this->actingAs($hrStaff);

        $this->assertFalse(VerifyEmployeeJoiningDate::canAccess());
        $this->expectException(AuthorizationException::class);
        app(JoiningDateCorrectionService::class)->preview($employee, '2025-02-28', $hrStaff);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
