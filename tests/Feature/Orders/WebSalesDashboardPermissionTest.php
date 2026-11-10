<?php

namespace Tests\Feature\Orders;

use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\WebSalesPermission;
use App\Filament\Pages\WebSalesDashboard;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Orders\WebSalesDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebSalesDashboardPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_permission_is_registered_as_a_standalone_module(): void
    {
        $module = app(AccessControlModuleRegistry::class)->keyed()['web_sales_dashboard'];

        $this->assertSame('Web Sales Dashboard', $module['label']);
        $this->assertSame([WebSalesPermission::Dashboard->value], $module['view_keys']);
        $this->assertSame([], $module['edit_keys']);
        $this->assertSame([], $module['advanced_keys']);
    }

    public function test_owner_and_admin_are_allowed_but_staff_requires_an_explicit_grant(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $staff = $this->user(EmployeeRole::Staff);
        $authorization = app(WebSalesAuthorization::class);

        $this->assertTrue($authorization->allows($owner, WebSalesPermission::Dashboard));
        $this->assertTrue($authorization->allows($admin, WebSalesPermission::Dashboard));
        $this->assertFalse($authorization->allows($staff, WebSalesPermission::Dashboard));
    }

    public function test_employee_specific_grant_allows_dashboard_without_order_view_permission(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => WebSalesPermission::Dashboard->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Dashboard access for Web Sales reporting',
        ]);

        $this->actingAs($staff->refresh());
        $this->assertTrue(WebSalesDashboard::canAccess());
        $this->assertTrue(WebSalesDashboard::shouldRegisterNavigation());
        Livewire::test(WebSalesDashboard::class)->assertOk();
    }

    public function test_explicit_deny_overrides_an_admin_default(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => WebSalesPermission::Dashboard->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Dashboard access revoked',
        ]);

        $this->actingAs($admin->refresh());
        $this->assertFalse(WebSalesDashboard::canAccess());
        $this->assertFalse(WebSalesDashboard::shouldRegisterNavigation());
        Livewire::test(WebSalesDashboard::class)->assertForbidden();
    }

    public function test_denied_dashboard_cannot_open_the_page_or_call_the_data_service(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff);
        $this->assertFalse(WebSalesDashboard::canAccess());
        $this->assertFalse(WebSalesDashboard::shouldRegisterNavigation());
        Livewire::test(WebSalesDashboard::class)->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(WebSalesDashboardService::class)->metrics($staff, CarbonImmutable::today(), CarbonImmutable::today());
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
