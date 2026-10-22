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
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\JoiningDateCorrectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_hr_staff_with_explicit_joining_date_permission_and_team_scope_can_use_the_verification_workflow(): void
    {
        $granter = $this->user(EmployeeRole::Admin);
        $hrStaff = $this->user(EmployeeRole::Staff);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $team = Team::query()->create(['name' => 'Authorized HR Team', 'status' => true]);
        $hrStaff->employee->forceFill(['team_id' => $team->id])->save();
        $employee->forceFill(['team_id' => $team->id])->save();
        EmployeePermissionOverride::query()->create([
            'employee_id' => $hrStaff->employee->id,
            'permission_key' => HrPermission::LeaveJoiningDateCorrect->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $granter->id,
            'reason' => 'Authorized HR joining-date verification',
        ]);
        $this->override($hrStaff, HrPermission::LeaveViewTeam, EmployeePermissionEffect::Allow);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($hrStaff->employee->id);

        $this->actingAs($hrStaff);
        $this->assertTrue(VerifyEmployeeJoiningDate::canAccess());
        $this->get(VerifyEmployeeJoiningDate::getUrl(['employee' => $employee->id]))->assertOk();
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

    #[DataProvider('managementRolesAndEmployeeStatuses')]
    public function test_owner_and_admin_can_review_preview_and_save_active_and_inactive_employees(EmployeeRole $role, bool $active): void
    {
        $actor = $this->user($role);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $employee->forceFill(['status' => $active, 'joining_date' => null])->save();

        $this->actingAs($actor)->get(VerifyEmployeeJoiningDate::getUrl(['employee' => $employee->id]))->assertOk();
        $preview = app(JoiningDateCorrectionService::class)->preview($employee, '2025-03-15', $actor);
        $this->assertSame($employee->employee_id, $preview['employee_id']);

        Livewire::actingAs($actor)->test(VerifyEmployeeJoiningDate::class, ['employee' => $employee])
            ->set('joiningDate', '2025-03-15')->set('reason', 'Verified original HR file')
            ->set('confirmed', true)->call('save')->assertHasNoErrors();

        $this->assertSame('2025-03-15', $employee->fresh()->joining_date->toDateString());
        $this->assertSame($active, $employee->fresh()->status);
        $this->assertDatabaseHas('employee_joining_date_audits', [
            'employee_id' => $employee->id, 'actor_user_id' => $actor->id, 'action' => 'verified',
        ]);
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public static function managementRolesAndEmployeeStatuses(): array
    {
        return [
            'Owner active employee' => [EmployeeRole::Owner, true],
            'Owner inactive employee' => [EmployeeRole::Owner, false],
            'Admin active employee' => [EmployeeRole::Admin, true],
            'Admin inactive employee' => [EmployeeRole::Admin, false],
        ];
    }

    public function test_joining_date_permission_alone_does_not_authorize_another_employee(): void
    {
        $actor = $this->user(EmployeeRole::Staff);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $this->override($actor, HrPermission::LeaveJoiningDateCorrect, EmployeePermissionEffect::Allow);

        $this->assertEmployeeDenied($actor, $employee);
        $this->assertTrue(app(JoiningDateCorrectionService::class)->canVerifyEmployee($actor, $actor->employee));
    }

    #[DataProvider('restrictedEmployees')]
    public function test_team_scoped_hr_cannot_use_urls_previews_or_saves_outside_the_existing_employee_scope(string $targetScope, bool $active): void
    {
        $actor = $this->user(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'HR Authorized Team', 'status' => true]);
        $other = Team::query()->create(['name' => 'Restricted Other Team', 'status' => true]);
        $actor->employee->forceFill(['team_id' => $team->id])->save();
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $employee->forceFill([
            'joining_date' => null, 'status' => $active,
            'team_id' => match ($targetScope) {
                'same' => $team->id, 'other' => $other->id, default => null
            },
        ])->save();
        $this->override($actor, HrPermission::LeaveJoiningDateCorrect, EmployeePermissionEffect::Allow);
        $this->override($actor, HrPermission::LeaveViewTeam, EmployeePermissionEffect::Allow);

        $this->assertEmployeeDenied($actor, $employee);
    }

    public static function restrictedEmployees(): array
    {
        return [
            'Other team active' => ['other', true],
            'Other team inactive' => ['other', false],
            'No team active' => ['none', true],
            'Own team inactive' => ['same', false],
        ];
    }

    #[DataProvider('verificationActions')]
    public function test_open_page_rechecks_employee_scope_on_every_livewire_request(string $action): void
    {
        $actor = $this->user(EmployeeRole::Manager);
        $team = Team::query()->create(['name' => 'Initial HR Team', 'status' => true]);
        $other = Team::query()->create(['name' => 'Moved Employee Team', 'status' => true]);
        $actor->employee->forceFill(['team_id' => $team->id])->save();
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $employee->forceFill(['team_id' => $team->id, 'joining_date' => null])->save();
        $this->override($actor, HrPermission::LeaveJoiningDateCorrect, EmployeePermissionEffect::Allow);
        $component = Livewire::actingAs($actor)->test(VerifyEmployeeJoiningDate::class, ['employee' => $employee])
            ->set('joiningDate', '2025-03-15')->set('reason', 'Verified HR file')->set('confirmed', true);

        // The browser still holds its old model snapshot, but the target is no
        // longer in scope. Even a refresh must not disclose the old preview.
        Employee::query()->whereKey($employee->id)->update(['team_id' => $other->id]);
        if ($action === 'update') {
            $component->set('joiningDate', '2025-03-14')->assertForbidden();
        } else {
            $component->call($action)->assertForbidden();
        }

        $this->assertNull($employee->fresh()->joining_date);
        $this->assertDatabaseCount('employee_joining_date_audits', 0);
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public static function verificationActions(): array
    {
        return ['Refresh' => ['$refresh'], 'Preview' => ['refreshPreview'], 'Save' => ['save'], 'Form update' => ['update']];
    }

    public function test_existing_page_and_service_deny_access_after_target_is_inactive_or_permission_is_revoked(): void
    {
        $actor = $this->user(EmployeeRole::Staff);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $this->override($actor, HrPermission::LeaveJoiningDateCorrect, EmployeePermissionEffect::Allow);
        $this->override($actor, HrPermission::LeaveViewAll, EmployeePermissionEffect::Allow);
        $component = Livewire::actingAs($actor)->test(VerifyEmployeeJoiningDate::class, ['employee' => $employee]);
        Employee::query()->whereKey($employee->id)->update(['status' => false]);
        $component->call('$refresh')->assertForbidden();
        $this->assertEmployeeDenied($actor, $employee);

        Employee::query()->whereKey($employee->id)->update(['status' => true]);
        $component = Livewire::actingAs($actor)->test(VerifyEmployeeJoiningDate::class, ['employee' => $employee->fresh()]);
        $this->override($actor, HrPermission::LeaveJoiningDateCorrect, EmployeePermissionEffect::Deny);
        $component->call('save')->assertForbidden();
        $this->assertEmployeeDenied($actor, $employee);
    }

    public function test_admin_explicit_permission_denial_still_applies_to_inactive_employees(): void
    {
        $actor = $this->user(EmployeeRole::Admin);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $employee->forceFill(['status' => false])->save();
        $this->override($actor, HrPermission::LeaveJoiningDateCorrect, EmployeePermissionEffect::Deny);

        $this->assertEmployeeDenied($actor, $employee);
    }

    public function test_admin_explicit_employee_scope_denial_is_not_bypassed(): void
    {
        $actor = $this->user(EmployeeRole::Admin);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $actor->employee->forceFill(['team_id' => null])->save();
        $this->override($actor, HrPermission::LeaveViewAll, EmployeePermissionEffect::Deny);

        $this->assertEmployeeDenied($actor, $employee);
        $employee->forceFill(['status' => false])->save();
        $this->assertEmployeeDenied($actor, $employee);
    }

    private function assertEmployeeDenied(User $actor, Employee $employee): void
    {
        $before = $employee->fresh()->getRawOriginal();
        $service = app(JoiningDateCorrectionService::class);
        $this->assertFalse($service->canVerifyEmployee($actor, $employee));
        $this->actingAs($actor)->get(VerifyEmployeeJoiningDate::getUrl(['employee' => $employee->id]))->assertForbidden();
        Livewire::actingAs($actor)->test(VerifyEmployeeJoiningDate::class, ['employee' => $employee])->assertForbidden();
        foreach (['preview', 'save'] as $action) {
            try {
                if ($action === 'preview') {
                    $service->preview($employee, '2025-03-15', $actor);
                } else {
                    $service->verifyOrCorrect($employee, [
                        'joining_date' => '2025-03-15', 'reason' => 'Unauthorized attempted correction', 'confirmed' => true,
                    ], $actor);
                }
                $this->fail('An out-of-scope employee was accepted for '.$action);
            } catch (AuthorizationException) {
                $this->assertSame($before, $employee->fresh()->getRawOriginal());
            }
        }
        $this->assertDatabaseCount('employee_joining_date_audits', 0);
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    private function override(User $actor, HrPermission $permission, EmployeePermissionEffect $effect): void
    {
        EmployeePermissionOverride::query()->updateOrCreate([
            'employee_id' => $actor->employee->id, 'permission_key' => $permission->value,
        ], ['effect' => $effect, 'granted_by_user_id' => $actor->id, 'reason' => 'Joining-date authorization regression']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($actor->employee->id);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
