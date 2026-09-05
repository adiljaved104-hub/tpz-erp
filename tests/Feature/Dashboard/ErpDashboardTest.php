<?php

namespace Tests\Feature\Dashboard;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\ErpDashboardOverview;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Dashboard\ErpDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ErpDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_employee_dashboard_renders_compact_operational_cards_and_period_switches(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');

        Livewire::actingAs($owner)->test(ErpDashboardOverview::class)
            ->assertSee('Operational Overview')
            ->assertSee('Sales &amp; Orders', false)
            ->assertSee('Inventory')
            ->assertSee('Service &amp; Claims', false)
            ->assertSee('Work')
            ->assertSee('HR')
            ->assertSee('Orders Today')
            ->assertSee('Inventory Units')
            ->assertSeeHtml('class="erp-dashboard-card-grid"')
            ->assertSeeHtml('grid-template-columns: repeat(4, minmax(0, 1fr))')
            ->assertSeeHtml('class="erp-dashboard-inventory-grid"')
            ->assertSeeHtml('data-inventory-intelligence-grid')
            ->assertSeeHtml('-webkit-line-clamp: 2')
            ->assertSeeHtml('data-dashboard-card="orders"')
            ->assertSee(OrderResource::getUrl(), false)
            ->assertSee('No items need your attention.')
            ->assertSet('period', 'today')
            ->call('setPeriod', 'week')
            ->assertSet('period', 'week')
            ->assertSee('Orders This Week');

        $this->actingAs($owner)->get('/admin')
            ->assertOk()
            ->assertSee('Operational Overview');
    }

    public function test_owner_sees_inventory_value_while_admin_payload_omits_financial_inventory_columns(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        $warehouse = Warehouse::factory()->create();
        $inventory = ProductInventory::factory()->create([
            'product_id' => Product::factory(),
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 2,
            'reserved_quantity' => 0,
            'damaged_quantity' => 2,
            'average_cost' => '1250.0000',
        ]);

        $ownerKeys = app(ErpDashboardService::class)->forUser($owner)['cards']->pluck('key');
        $this->assertContains('inventory_value', $ownerKeys);

        Livewire::actingAs($owner)->test(ErpDashboardOverview::class)
            ->assertSee('Inventory Value')
            ->assertSee('Damaged stock needs action')
            ->assertSee('View');
        Livewire::actingAs($admin)->test(ErpDashboardOverview::class)
            ->assertDontSee('Inventory Value');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $adminData = app(ErpDashboardService::class)->forUser($admin);
        $inventoryQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from "product_inventories"'),
        );
        DB::disableQueryLog();

        $this->assertNotContains('inventory_value', $adminData['cards']->pluck('key'));
        $this->assertNotContains('gross_profit', $adminData['cards']->pluck('key'));
        $this->assertTrue($inventoryQueries->isNotEmpty());
        $this->assertTrue($inventoryQueries->every(
            fn (array $query): bool => ! str_contains(strtolower($query['query']), 'average_cost'),
        ));
        $this->assertDatabaseHas('product_inventories', ['id' => $inventory->id, 'average_cost' => '1250.0000']);
    }

    public function test_inventory_intelligence_uses_bounded_panels_and_compact_product_labels(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create([
            'sku' => 'TPZ-000003',
            'name' => 'Lenovo Yoga 7i 14 Inch Convertible Laptop With An Intentionally Long Marketplace Title',
            'brand' => 'Lenovo',
            'model' => 'Yoga 7i 14',
        ]);
        ProductInventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'available_quantity' => 1,
            'reserved_quantity' => 0,
        ]);

        Livewire::actingAs($owner)->test(ErpDashboardOverview::class)
            ->assertSeeHtml('data-inventory-intelligence-grid')
            ->assertSeeHtml('class="erp-dashboard-inventory-panel"')
            ->assertSeeHtml('<div class="erp-dashboard-list-sku">TPZ-000003</div>')
            ->assertSeeHtml('class="erp-dashboard-list-title"')
            ->assertSee('Lenovo · Yoga 7i 14')
            ->assertSeeHtml('title="Lenovo Yoga 7i 14 Inch Convertible Laptop With An Intentionally Long Marketplace Title"')
            ->assertSee('No fulfilled sales in this period.')
            ->assertSee('No fast-selling low-stock products in this period.');
    }

    public function test_staff_inventory_intelligence_is_responsibility_scoped_without_scope_selectors(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create([
            'sku' => 'TPZ-000003',
            'name' => 'Lenovo Yoga 7i 14 Inch Convertible Laptop With An Intentionally Long Marketplace Title',
            'brand' => 'Lenovo',
            'model' => 'Yoga 7i 14',
        ]);
        ProductInventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'available_quantity' => 1,
            'reserved_quantity' => 0,
        ]);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->employee->id,
            'assigned_by_user_id' => $owner->id,
        ]);
        DB::table('responsibility_assignment_products')->insert([
            'assignment_id' => $assignment->id,
            'product_id' => $product->id,
        ]);

        Livewire::actingAs($staff)->test(ErpDashboardOverview::class)
            ->assertSee('My Inventory Intelligence')
            ->assertDontSeeHtml('data-inventory-scope-controls')
            ->assertSee('TPZ-000003')
            ->assertSee('Lenovo · Yoga 7i 14')
            ->assertSeeHtml('title="Lenovo Yoga 7i 14 Inch Convertible Laptop With An Intentionally Long Marketplace Title"')
            ->assertSeeHtml('-webkit-line-clamp: 2');
    }

    public function test_employee_deny_override_removes_order_metrics_instead_of_loading_them(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => OrderPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Dashboard authorization regression',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);

        $keys = app(ErpDashboardService::class)->forUser($admin)['cards']->pluck('key');

        $this->assertNotContains('orders', $keys);
        $this->assertNotContains('revenue', $keys);

        Livewire::actingAs($admin)->test(ErpDashboardOverview::class)
            ->assertDontSee('Sales &amp; Orders', false)
            ->assertSee('Inventory');
    }

    public function test_staff_dashboard_remains_personal_and_never_exposes_owner_inventory_financials(): void
    {
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        $data = app(ErpDashboardService::class)->forUser($staff);
        $keys = $data['cards']->pluck('key');

        $this->assertNotContains('inventory_value', $keys);
        $this->assertNotContains('gross_profit', $keys);
        $this->assertContains('notifications', $keys);
        $this->assertSame('Staff', $data['role_label']);
    }

    public function test_manager_attendance_cards_are_team_scoped_and_staff_cards_are_personal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 12:00:00', config('app.timezone')));
        $team = Team::query()->create(['name' => 'E-commerce', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Warehouse', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager, 'Manager', $team);
        $staff = $this->user(EmployeeRole::Staff, 'Staff', $team);
        $other = $this->user(EmployeeRole::Staff, 'Other', $otherTeam);

        $this->attendance($manager->employee->id, 'present');
        $this->attendance($staff->employee->id, 'late');
        $this->attendance($staff->employee->id, 'late', CarbonImmutable::today(config('app.timezone'))->subDay()->toDateString());
        $this->attendance($other->employee->id, 'absent');
        $this->attendance($other->employee->id, 'late', CarbonImmutable::today(config('app.timezone'))->subDay()->toDateString());

        $managerCards = app(ErpDashboardService::class)->forUser($manager, 'week')['cards']->keyBy('key');
        $staffCards = app(ErpDashboardService::class)->forUser($staff, 'week')['cards']->keyBy('key');

        $this->assertSame(1, $managerCards['attendance_present']['value']);
        $this->assertSame(1, $managerCards['attendance_late']['value']);
        $this->assertSame(0, $managerCards['attendance_absent']['value']);
        $this->assertSame(0, $staffCards['attendance_present']['value']);
        $this->assertSame(2, $staffCards['attendance_late']['value']);
        $this->assertSame(0, $staffCards['attendance_absent']['value']);
        $this->assertSame('2 total late occurrences', $managerCards['attendance_late']['description']);
        $this->assertSame('Late Occurrences This Week', $staffCards['attendance_late']['label']);
    }

    public function test_management_attendance_cards_count_unique_employees_and_keep_occurrence_totals_for_custom_period(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $first = $this->user(EmployeeRole::Staff, 'First Staff');
        $second = $this->user(EmployeeRole::Staff, 'Second Staff');

        $this->attendance($first->employee->id, 'late', '2026-08-02');
        $this->attendance($first->employee->id, 'late', '2026-08-03');
        $this->attendance($second->employee->id, 'late', '2026-08-04');
        $this->attendance($first->employee->id, 'present', '2026-08-05');
        $this->attendance($second->employee->id, 'present', '2026-08-06');
        $this->attendance($second->employee->id, 'absent', '2026-08-07');
        $this->attendance($first->employee->id, 'late', '2026-07-31');

        $cards = app(ErpDashboardService::class)
            ->forUser($owner, 'custom', '2026-08-01', '2026-08-31')['cards']
            ->keyBy('key');

        $this->assertSame(2, $cards['attendance_present']['value']);
        $this->assertSame(2, $cards['attendance_late']['value']);
        $this->assertSame(1, $cards['attendance_absent']['value']);
        $this->assertSame('3 total late occurrences', $cards['attendance_late']['description']);
        $this->assertSame('1 total absence occurrence', $cards['attendance_absent']['description']);
        $this->assertSame('Present Employees Custom Range', $cards['attendance_present']['label']);
    }

    public function test_inactive_employee_cannot_render_dashboard_widget(): void
    {
        $staff = $this->user(EmployeeRole::Staff, 'Inactive Staff');
        $this->actingAs($staff);
        $staff->employee->update(['status' => false]);

        $this->assertFalse(ErpDashboardOverview::canView());
    }

    private function attendance(int $employeeId, string $status, ?string $date = null): void
    {
        DB::table('employee_attendances')->insert([
            'employee_id' => $employeeId,
            'attendance_date' => $date ?? CarbonImmutable::today(config('app.timezone'))->toDateString(),
            'status' => $status,
            'source' => 'manual',
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(EmployeeRole $role, string $name, ?Team $team = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name,
            'email' => $user->email,
            'status' => true,
            'team_id' => $team?->id,
        ]);

        return $user->refresh();
    }
}
