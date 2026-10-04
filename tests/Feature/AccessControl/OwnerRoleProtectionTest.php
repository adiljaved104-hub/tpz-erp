<?php

namespace Tests\Feature\AccessControl;

use App\Actions\Employees\ChangeEmployeeRole;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PeoplePermission;
use App\Enums\ProductPermission;
use App\Exceptions\LastOwnerException;
use App\Filament\Pages\Administration\AccessControl;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OwnerRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    public static function promotionTargets(): array
    {
        return [
            'Staff' => [EmployeeRole::Staff, false],
            'Manager' => [EmployeeRole::Manager, false],
            'another Admin' => [EmployeeRole::Admin, false],
            'self' => [EmployeeRole::Admin, true],
        ];
    }

    #[DataProvider('promotionTargets')]
    public function test_admin_with_owner_granted_role_permission_cannot_promote_any_target_to_owner(EmployeeRole $targetRole, bool $self): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $admin = $this->employee(EmployeeRole::Admin);
        $target = $self ? $admin : $this->employee($targetRole);
        $this->grantRoleManagement($admin, $owner);

        $this->assertTrue($admin->user->can('changeRole', $target));
        $this->assertFalse($admin->user->can('changeRole', [$target, EmployeeRole::Owner]));
        $this->assertDenied(fn () => app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Owner, $admin->user));
        $this->assertSame($targetRole, $target->fresh()->role);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'employee.role_changed', 'subject_id' => $target->id]);
        $this->assertFalse($admin->user->can('viewFinancialData'));
    }

    public function test_admin_without_override_cannot_promote_to_owner(): void
    {
        $admin = $this->employee(EmployeeRole::Admin);
        $target = $this->employee(EmployeeRole::Staff);
        $this->assertFalse($admin->user->can('changeRole', $target));
        $this->assertDenied(fn () => app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Owner, $admin->user));
        $this->assertSame(EmployeeRole::Staff, $target->fresh()->role);
    }

    public function test_admin_cannot_grant_revoke_or_reset_role_management_authority(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $admin = $this->employee(EmployeeRole::Admin);
        $delegate = $this->employee(EmployeeRole::Admin);
        $service = app(EmployeePermissionOverrideService::class);

        $this->assertDenied(fn () => $service->change($delegate, PeoplePermission::EmployeeChangeRole->value, EmployeePermissionEffect::Allow, null, $admin->user));
        $this->assertDatabaseCount('employee_permission_overrides', 0);
        $this->grantRoleManagement($delegate, $owner);
        foreach ([EmployeePermissionEffect::Deny, null] as $effect) {
            $this->assertDenied(fn () => $service->change($delegate, PeoplePermission::EmployeeChangeRole->value, $effect, null, $admin->user));
        }
        $this->assertDatabaseHas('employee_permission_overrides', ['employee_id' => $delegate->id, 'permission_key' => PeoplePermission::EmployeeChangeRole->value, 'effect' => 'allow']);
    }

    public function test_admin_bulk_override_rejects_entire_batch_including_unrelated_changes(): void
    {
        $admin = $this->employee(EmployeeRole::Admin);
        $first = $this->employee(EmployeeRole::Admin);
        $second = $this->employee(EmployeeRole::Staff);

        $this->assertDenied(fn () => app(EmployeePermissionOverrideService::class)->changeMany(
            [$first->id, $second->id],
            [PeoplePermission::EmployeeView->value => EmployeePermissionEffect::Allow, PeoplePermission::EmployeeChangeRole->value => EmployeePermissionEffect::Allow],
            null,
            $admin->user,
        ));
        $this->assertDatabaseCount('employee_permission_overrides', 0);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'employee_permission.allowed']);
    }

    public function test_tampered_bulk_access_control_cannot_persist_role_authority(): void
    {
        $admin = $this->employee(EmployeeRole::Admin);
        $first = $this->employee(EmployeeRole::Admin);
        $second = $this->employee(EmployeeRole::Staff);
        $component = Livewire::actingAs($admin->user)->test(AccessControl::class)
            ->call('toggleEmployeeSelection', $first->id)
            ->call('toggleEmployeeSelection', $second->id);
        // Set public Livewire state directly rather than trusting a staged UI button.
        $settings = $component->instance()->draftSettings;
        $settings[PeoplePermission::EmployeeView->value] = 'allow';
        $settings[PeoplePermission::EmployeeChangeRole->value] = 'allow';
        $component->set('draftSettings', $settings)->call('saveChanges');

        $this->assertDatabaseCount('employee_permission_overrides', 0);
        $this->assertSame(EmployeeRole::Admin, $first->fresh()->role);
    }

    public function test_tampered_livewire_employee_edit_to_owner_is_forbidden_and_rolls_back_profile(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $admin = $this->employee(EmployeeRole::Admin);
        $target = $this->employee(EmployeeRole::Staff);
        $this->grantRoleManagement($admin, $owner);

        Livewire::actingAs($admin->user)->test(EditEmployee::class, ['record' => $target->getRouteKey()])
            ->set('data.role', EmployeeRole::Owner->value)
            ->set('data.name', 'Tampered profile')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(EmployeeRole::Staff, $target->fresh()->role);
        $this->assertSame($target->name, $target->fresh()->name);
        $this->assertSame($target->user_id, $target->fresh()->user_id);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'employee.role_changed', 'subject_id' => $target->id]);
    }

    public function test_owner_can_promote_and_demote_when_another_active_owner_exists(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $target = $this->employee(EmployeeRole::Staff);
        $this->assertTrue($owner->user->can('changeRole', [$target, EmployeeRole::Owner]));

        app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Owner, $owner->user);
        $this->assertSame(EmployeeRole::Owner, $target->fresh()->role);
        $this->assertSame($target->user_id, $target->fresh()->user_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'employee.role_changed', 'subject_id' => $target->id]);
        app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Admin, $owner->user);
        $this->assertSame(EmployeeRole::Admin, $target->fresh()->role);
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $this->expectException(LastOwnerException::class);
        app(ChangeEmployeeRole::class)->handle($owner, EmployeeRole::Admin, $owner->user);
    }

    public function test_owner_delegated_admin_role_management_below_owner_still_works(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $admin = $this->employee(EmployeeRole::Admin);
        $target = $this->employee(EmployeeRole::Staff);
        $this->grantRoleManagement($admin, $owner);
        foreach ([EmployeeRole::Manager, EmployeeRole::Admin, EmployeeRole::Staff] as $role) {
            app(ChangeEmployeeRole::class)->handle($target, $role, $admin->user);
            $this->assertSame($role, $target->fresh()->role);
        }
        $this->assertDenied(fn () => app(ChangeEmployeeRole::class)->handle($owner, EmployeeRole::Admin, $admin->user));
    }

    public function test_manager_and_staff_boundaries_and_owner_granted_non_owner_role_changes_are_preserved(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $actorRole) {
            $actor = $this->employee($actorRole);
            $target = $this->employee(EmployeeRole::Staff);
            $this->assertDenied(fn () => app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Manager, $actor->user));
            $this->grantRoleManagement($actor, $owner);
            $this->assertDenied(fn () => app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Owner, $actor->user));
            app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Manager, $actor->user);
            $this->assertSame(EmployeeRole::Manager, $target->fresh()->role);
        }
    }

    public function test_admin_cannot_gain_owner_financial_access_through_overrides(): void
    {
        $admin = $this->employee(EmployeeRole::Admin);
        $delegate = $this->employee(EmployeeRole::Admin);
        $this->assertDenied(fn () => app(EmployeePermissionOverrideService::class)->change($delegate, ProductPermission::ViewCostPrice->value, EmployeePermissionEffect::Allow, 'Attempted delegation', $admin->user));
        $this->assertFalse($delegate->user->can('viewFinancialData'));
        $this->assertDatabaseCount('employee_permission_overrides', 0);
    }

    public function test_cached_owner_identity_cannot_promote_or_delegate_after_demotion(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $actor = $owner->user;
        $actor->load('employee');
        $target = $this->employee(EmployeeRole::Staff);
        Employee::query()->whereKey($owner->id)->update(['role' => EmployeeRole::Admin]);

        $this->assertDenied(fn () => app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Owner, $actor));
        $this->assertDenied(fn () => app(EmployeePermissionOverrideService::class)->change($target, PeoplePermission::EmployeeChangeRole->value, EmployeePermissionEffect::Allow, null, $actor));
        $this->assertSame(EmployeeRole::Staff, $target->fresh()->role);
        $this->assertDatabaseCount('employee_permission_overrides', 0);
    }

    public function test_inactive_owner_cannot_promote_or_delegate_role_authority(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $actor = $owner->user;
        $actor->load('employee');
        $target = $this->employee(EmployeeRole::Staff);
        Employee::query()->whereKey($owner->id)->update(['status' => false]);

        $this->assertDenied(fn () => app(ChangeEmployeeRole::class)->handle($target, EmployeeRole::Owner, $actor));
        $this->assertDenied(fn () => app(EmployeePermissionOverrideService::class)->change($target, PeoplePermission::EmployeeChangeRole->value, EmployeePermissionEffect::Allow, null, $actor));
        $this->assertSame(EmployeeRole::Staff, $target->fresh()->role);
        $this->assertDatabaseCount('employee_permission_overrides', 0);
    }

    private function grantRoleManagement(Employee $target, Employee $owner): void
    {
        app(EmployeePermissionOverrideService::class)->change($target, PeoplePermission::EmployeeChangeRole->value, EmployeePermissionEffect::Allow, null, $owner->user);
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Owner-sensitive operation must be denied.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function employee(EmployeeRole $role): Employee
    {
        $employee = Employee::factory()->role($role)->create();
        $email = $role->value.'-'.str()->random(10).'@techpointzone.com';
        $employee->user()->update(['email' => $email]);
        $employee->update(['email' => $email]);

        return $employee->refresh()->load('user');
    }
}
