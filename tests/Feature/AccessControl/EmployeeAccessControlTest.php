<?php

namespace Tests\Feature\AccessControl;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\ProductPermission;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ManageEmployeeAccess;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ProductAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_allow_deny_and_restore_inherited_role_access(): void
    {
        $owner = $this->employee(EmployeeRole::Owner)->user;
        $staff = $this->employee(EmployeeRole::Staff);
        $manager = $this->employee(EmployeeRole::Manager);
        $service = app(EmployeePermissionOverrideService::class);

        $this->assertFalse(app(OrderAuthorization::class)->allows($staff->user, OrderPermission::Fulfill));
        $service->change($staff, OrderPermission::Fulfill->value, EmployeePermissionEffect::Allow, null, $owner);
        $this->assertTrue(app(OrderAuthorization::class)->allows($staff->user, OrderPermission::Fulfill));

        $this->assertTrue(app(OrderAuthorization::class)->allows($manager->user, OrderPermission::View));
        $service->change($manager, OrderPermission::View->value, EmployeePermissionEffect::Deny, null, $owner);
        $this->assertFalse(app(OrderAuthorization::class)->allows($manager->user, OrderPermission::View));
        $service->change($manager, OrderPermission::View->value, null, null, $owner);
        $this->assertTrue(app(OrderAuthorization::class)->allows($manager->user, OrderPermission::View));

        $this->assertDatabaseHas('activity_logs', ['event' => 'employee_permission.allowed', 'subject_id' => $staff->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'employee_permission.denied', 'subject_id' => $manager->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'employee_permission.inherited', 'subject_id' => $manager->id]);
    }

    public function test_admin_can_manage_non_financial_access_but_not_owner_or_financial_access(): void
    {
        $admin = $this->employee(EmployeeRole::Admin)->user;
        $staff = $this->employee(EmployeeRole::Staff);
        $owner = $this->employee(EmployeeRole::Owner);
        $service = app(EmployeePermissionOverrideService::class);

        $service->change($staff, OrderPermission::Fulfill->value, EmployeePermissionEffect::Allow, null, $admin);
        $this->assertDatabaseHas('employee_permission_overrides', [
            'employee_id' => $staff->id,
            'permission_key' => OrderPermission::Fulfill->value,
            'effect' => 'allow',
        ]);

        foreach ([
            fn () => $service->change($owner, OrderPermission::View->value, EmployeePermissionEffect::Deny, null, $admin),
            fn () => $service->change($staff, ProductPermission::ViewCostPrice->value, EmployeePermissionEffect::Allow, 'Needed', $admin),
        ] as $operation) {
            try {
                $operation();
                $this->fail('The protected permission change should be denied.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_manager_and_staff_cannot_access_or_change_permissions(): void
    {
        $target = $this->employee(EmployeeRole::Staff);

        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $actor = $this->employee($role)->user;
            $this->actingAs($actor)
                ->get(EmployeeResource::getUrl('access', ['record' => $target]))
                ->assertForbidden();

            try {
                app(EmployeePermissionOverrideService::class)->change(
                    $target,
                    OrderPermission::Fulfill->value,
                    EmployeePermissionEffect::Allow,
                    null,
                    $actor,
                );
                $this->fail("{$role->value} must not change Employee permissions.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_access_page_shows_friendly_labels_and_updates_overrides_without_hard_coding_an_employee(): void
    {
        $owner = $this->employee(EmployeeRole::Owner)->user;
        $staff = $this->employee(EmployeeRole::Staff);
        $token = sha1(OrderPermission::Fulfill->value);

        Livewire::actingAs($owner)
            ->test(ManageEmployeeAccess::class, ['record' => $staff->getRouteKey()])
            ->assertSee('Ship Orders')
            ->assertSee(OrderPermission::Fulfill->value)
            ->set("changes.{$token}.effect", 'allow')
            ->call('savePermission', OrderPermission::Fulfill->value)
            ->assertHasNoErrors();

        $this->assertTrue(app(OrderAuthorization::class)->allows($staff->user, OrderPermission::Fulfill));
    }

    public function test_financial_access_requires_owner_and_reason_and_is_independent(): void
    {
        $owner = $this->employee(EmployeeRole::Owner)->user;
        $staff = $this->employee(EmployeeRole::Staff);
        $service = app(EmployeePermissionOverrideService::class);

        try {
            $service->change($staff, ProductPermission::ViewCostPrice->value, EmployeePermissionEffect::Allow, null, $owner);
            $this->fail('Financial access must require a reason.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $service->change($staff, ProductPermission::ViewCostPrice->value, EmployeePermissionEffect::Allow, 'Approved operational need', $owner);

        $this->assertTrue(app(ProductAuthorization::class)->allows($staff->user, ProductPermission::ViewCostPrice));
        $this->assertFalse(app(OrderAuthorization::class)->allows($staff->user, OrderPermission::ViewProfit));
    }

    public function test_owner_access_cannot_be_weakened_even_if_a_deny_row_exists(): void
    {
        $ownerEmployee = $this->employee(EmployeeRole::Owner);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $ownerEmployee->id,
            'permission_key' => OrderPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $ownerEmployee->user_id,
            'reason' => null,
        ]);

        $this->assertTrue(app(OrderAuthorization::class)->allows($ownerEmployee->user, OrderPermission::View));
    }

    public function test_database_uniqueness_and_request_cache_invalidation_are_enforced(): void
    {
        $owner = $this->employee(EmployeeRole::Owner)->user;
        $staff = $this->employee(EmployeeRole::Staff);
        $resolver = app(EmployeePermissionOverrideResolver::class);

        $this->assertNull($resolver->decision($staff->user, OrderPermission::Fulfill->value));
        app(EmployeePermissionOverrideService::class)->change(
            $staff,
            OrderPermission::Fulfill->value,
            EmployeePermissionEffect::Allow,
            null,
            $owner,
        );
        $this->assertTrue($resolver->decision($staff->user, OrderPermission::Fulfill->value));

        $this->expectException(QueryException::class);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->id,
            'permission_key' => OrderPermission::Fulfill->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
        ]);
    }

    public function test_effect_is_constrained_by_the_database(): void
    {
        $owner = $this->employee(EmployeeRole::Owner)->user;
        $staff = $this->employee(EmployeeRole::Staff);

        $this->expectException(QueryException::class);
        DB::table('employee_permission_overrides')->insert([
            'employee_id' => $staff->id,
            'permission_key' => OrderPermission::Fulfill->value,
            'effect' => 'invalid',
            'granted_by_user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function employee(EmployeeRole $role): Employee
    {
        return Employee::factory()->role($role)->create();
    }
}
