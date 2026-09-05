<?php

namespace Tests\Feature\Authorization;

use App\Enums\AuthSecurityPermission;
use App\Enums\BackupSettingsPermission;
use App\Enums\CatalogPermission;
use App\Enums\ChatPermission;
use App\Enums\CompanyProfilePermission;
use App\Enums\ComplaintPermission;
use App\Enums\ComponentPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\DamagedStockPermission;
use App\Enums\EmailSettingsPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ExpensePermission;
use App\Enums\HrPermission;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Enums\InvoicePermission;
use App\Enums\MarketplaceReturnPermission;
use App\Enums\NotificationRulePermission;
use App\Enums\OfficeFinancePermission;
use App\Enums\OrderPermission;
use App\Enums\PeoplePermission;
use App\Enums\PerformancePermission;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Enums\QuotationPermission;
use App\Enums\ResponsibilityPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\StockTransferPermission;
use App\Enums\TaskPermission;
use App\Enums\UpgradePermission;
use App\Enums\WarrantyRepairPermission;
use App\Enums\WebSalesPermission;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\CatalogAuthorization;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\PeopleAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionCatalogCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_completed_permission_is_exposed_once_in_access_control(): void
    {
        $enumClasses = [
            PeoplePermission::class, CatalogPermission::class, OrderPermission::class, PurchasePermission::class,
            WebSalesPermission::class, ExpensePermission::class,
            OfficeFinancePermission::class, BackupSettingsPermission::class,
            ProductPermission::class, InventoryPermission::class, ResponsibilityPermission::class,
            InventoryLocationPermission::class, StockTransferPermission::class, CustomerReturnPermission::class,
            MarketplaceReturnPermission::class, DamagedStockPermission::class, SafetClaimPermission::class,
            WarrantyRepairPermission::class, ComplaintPermission::class,
            TaskPermission::class, ChatPermission::class, HrPermission::class, PerformancePermission::class,
            EmailSettingsPermission::class, NotificationRulePermission::class,
            CompanyProfilePermission::class, InvoicePermission::class, QuotationPermission::class, AuthSecurityPermission::class,
            ComponentPermission::class, UpgradePermission::class,
        ];
        $expected = collect($enumClasses)->flatMap(fn (string $enum): array => array_column($enum::cases(), 'value'))->values()->all();
        $actual = collect(app(EmployeePermissionCatalog::class)->groups())->flatten(1)->pluck('key')->values()->all();

        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertCount(count(array_unique($actual)), $actual, 'A permission must belong to exactly one Access Control group.');
    }

    public function test_employee_overrides_drive_catalog_people_and_supplier_permissions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $admin = $this->user(EmployeeRole::Admin);
        $service = app(EmployeePermissionOverrideService::class);

        $service->change($staff->employee, CatalogPermission::BrandView->value, EmployeePermissionEffect::Allow, null, $owner);
        $service->change($staff->employee, PeoplePermission::TeamView->value, EmployeePermissionEffect::Allow, null, $owner);
        $service->change($staff->employee, PurchasePermission::SupplierView->value, EmployeePermissionEffect::Allow, null, $owner);
        $service->change($admin->employee, CatalogPermission::BrandView->value, EmployeePermissionEffect::Deny, null, $owner);

        $this->assertTrue(app(CatalogAuthorization::class)->allows($staff, CatalogPermission::BrandView));
        $this->assertTrue(app(PeopleAuthorization::class)->allows($staff, PeoplePermission::TeamView));
        $this->assertTrue(app(PurchaseAuthorization::class)->allows($staff, PurchasePermission::SupplierView));
        $this->assertFalse(app(CatalogAuthorization::class)->allows($admin, CatalogPermission::BrandView));
        $this->assertTrue(app(CatalogAuthorization::class)->allows($owner, CatalogPermission::BrandView));
    }

    public function test_team_management_is_policy_protected_and_hard_deletion_is_blocked(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        $this->assertTrue($owner->can('create', Team::class));
        $this->assertTrue($manager->can('view', $team));
        $this->assertFalse($manager->can('update', $team));
        $this->assertFalse($staff->can('view', $team));
        $this->assertFalse($owner->can('delete', $team));
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
