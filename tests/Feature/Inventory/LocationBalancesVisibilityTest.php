<?php

namespace Tests\Feature\Inventory;

use App\Contracts\InventoryPermissionResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Models\Employee;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Dashboard\DashboardInventoryIntelligenceService;
use App\Services\Dashboard\ErpDashboardService;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryReadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LocationBalancesVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_only_employee_filters_and_column_controls_preserve_scope_and_privacy(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $warehouse = Warehouse::factory()->create();
        $other = Warehouse::factory()->create();
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id, 'assign_stock_by_default' => false]);
        ResponsibilityAssignmentWarehouse::query()->create(['assignment_id' => $assignment->id, 'warehouse_id' => $warehouse->id]);
        $low = ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'available_quantity' => 2, 'reserved_quantity' => 1]);
        $out = ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'available_quantity' => 1, 'reserved_quantity' => 1]);
        $normal = ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'available_quantity' => 10, 'reserved_quantity' => 0]);
        $hidden = ProductInventory::factory()->create(['warehouse_id' => $other->id, 'available_quantity' => 1, 'reserved_quantity' => 0]);
        foreach ([$low, $out, $normal] as $inventory) {
            $this->ownedBalance($staff, $inventory);
        }
        $this->allow($owner, $staff, InventoryPermission::View->value);
        $this->allow($owner, $staff, InventoryPermission::ViewLocationBalances->value);
        $this->allow($owner, $staff, InventoryLocationPermission::View->value);
        $this->actingAs($staff->fresh());

        $page = Livewire::test(ListProductInventories::class)->assertOk()
            ->assertCanSeeTableRecords([$low, $out, $normal])->assertCanNotSeeTableRecords([$hidden]);
        $page->filterTable('warehouse_id', $other->id)->assertCountTableRecords(0)
            ->resetTableFilters()->filterTable('warehouse_id', $warehouse->id)->assertCountTableRecords(3)
            ->resetTableFilters()->filterTable('stock_status', 'low_stock')->assertCanSeeTableRecords([$low])->assertCountTableRecords(1)
            ->resetTableFilters()->filterTable('stock_status', 'out_of_stock')->assertCanSeeTableRecords([$out])->assertCountTableRecords(1)
            ->resetTableFilters()->filterTable('product_id', $normal->product_id)->assertCanSeeTableRecords([$normal])->assertCountTableRecords(1)
            ->resetTableFilters()->filterTable('product_id', $hidden->product_id)->assertCountTableRecords(0);
        $columns = $page->instance()->getTable()->getColumns();
        $this->assertTrue($columns['reserved_quantity']->isToggleable());
        $this->assertArrayNotHasKey('average_cost', $columns);
        $this->assertArrayNotHasKey('inventory_value', $columns);
        $this->assertFalse($assignment->refresh()->assign_stock_by_default);
        $this->assertDatabaseCount('inventory_allocation_balances', 3);
        try {
            app(InventoryAllocationPolicyService::class)->receiptAccount($low, null);
            $this->fail('Warehouse visibility must not select a receipt owner.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('No default stock responsibility', $exception->getMessage());
        }
        Livewire::test(InventoryOverview::class)->assertOk()->assertSee($low->product->sku)->assertDontSee($hidden->product->sku);
    }

    public function test_dashboard_links_apply_native_filters_and_product_stock_totals(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->actingAs($owner);
        $low = ProductInventory::factory()->create(['available_quantity' => 1, 'reserved_quantity' => 0]);
        $out = ProductInventory::factory()->create(['available_quantity' => 0, 'reserved_quantity' => 0]);
        $normal = ProductInventory::factory()->create(['available_quantity' => 9, 'reserved_quantity' => 0]);
        // A zero row for a well-stocked product must not turn it into an OOS product.
        ProductInventory::factory()->create(['product_id' => $normal->product_id, 'available_quantity' => 0, 'reserved_quantity' => 0]);
        $dashboard = app(ErpDashboardService::class)->forUser($owner);
        foreach (['low_stock' => $low, 'out_of_stock' => $out] as $key => $record) {
            $url = $dashboard['cards']->firstWhere('key', $key)['url'];
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($key, $query['filters']['stock_status']['value']);
            Livewire::withQueryParams($query)->test(ListProductInventories::class)
                ->assertCanSeeTableRecords([$record])->assertCountTableRecords(1);
        }
        $data = app(DashboardInventoryIntelligenceService::class)->forUser($owner, CarbonImmutable::now()->subDay(), CarbonImmutable::now());
        parse_str(parse_url($data['attention']->firstWhere('product_id', $low->product_id)['url'], PHP_URL_QUERY), $query);
        $this->assertEquals($low->product_id, $query['filters']['product_id']['value']);
        Livewire::withQueryParams($query)->test(ListProductInventories::class)->assertCanSeeTableRecords([$low])->assertCountTableRecords(1);
    }

    public function test_staff_with_required_access_sees_only_responsibility_scoped_inventory_on_both_screens(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $insideBrand = ProductBrand::factory()->create();
        $outsideBrand = ProductBrand::factory()->create();
        $inside = Product::factory()->create(['brand_id' => $insideBrand->id]);
        $outside = Product::factory()->create(['brand_id' => $outsideBrand->id]);
        $warehouse = Warehouse::factory()->create();
        $insideInventory = ProductInventory::factory()->create([
            'product_id' => $inside->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 4,
            'average_cost' => '125.0000',
        ]);
        $outsideInventory = ProductInventory::factory()->create([
            'product_id' => $outside->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 7,
            'average_cost' => '250.0000',
        ]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        ResponsibilityAssignmentBrand::query()->create([
            'assignment_id' => $assignment->id,
            'product_brand_id' => $insideBrand->id,
        ]);
        $this->ownedBalance($staff, $insideInventory);
        $this->allow($owner, $staff, InventoryPermission::View->value);
        $this->allow($owner, $staff, InventoryPermission::ViewLocationBalances->value);
        $this->allow($owner, $staff, InventoryLocationPermission::View->value);

        $this->actingAs($staff->fresh());

        $this->assertTrue(ProductInventoryResource::canViewAny());
        Livewire::test(ListProductInventories::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$insideInventory])
            ->assertCanNotSeeTableRecords([$outsideInventory]);
        Livewire::test(InventoryOverview::class)
            ->assertOk()
            ->assertSee($inside->sku)
            ->assertDontSee($outside->sku);
    }

    public function test_record_policy_uses_page_permissions_then_the_same_responsibility_scope_as_the_resource_query(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'available_quantity' => 2,
        ]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        ResponsibilityAssignmentBrand::query()->create([
            'assignment_id' => $assignment->id,
            'product_brand_id' => $brand->id,
        ]);
        $this->ownedBalance($staff, $inventory);
        $this->app->bind(InventoryPermissionResolver::class, fn () => new class implements InventoryPermissionResolver
        {
            public function allows(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): bool
            {
                return $inventory === null
                    && in_array($permission, [InventoryPermission::View, InventoryPermission::ViewLocationBalances], true);
            }
        });
        $this->actingAs($staff);

        $this->assertSame([$inventory->id], ProductInventoryResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue($staff->can('viewAny', ProductInventory::class));
        $this->assertTrue($staff->can('view', $inventory));
    }

    public function test_location_permission_is_still_required_independently(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->allow($owner, $staff, InventoryPermission::View->value);
        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            InventoryPermission::ViewLocationBalances->value,
            EmployeePermissionEffect::Deny,
            'Focused Location Balances denial test',
            $owner,
        );
        $this->actingAs($staff->fresh());

        $this->assertFalse(ProductInventoryResource::canViewAny());
        Livewire::test(ListProductInventories::class)->assertForbidden();
    }

    public function test_owner_and_admin_keep_all_rows_while_cost_projection_remains_owner_only(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'average_cost' => '125.0000',
        ]);
        ProductInventory::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'average_cost' => '250.0000',
        ]);

        $this->actingAs($owner);
        $this->assertCount(2, ProductInventoryResource::getEloquentQuery()->get());
        $this->assertArrayHasKey('average_cost', app(InventoryReadService::class)->inventories($owner)->firstOrFail()->getAttributes());

        $this->actingAs($admin);
        $this->assertCount(2, ProductInventoryResource::getEloquentQuery()->get());
        $this->assertArrayNotHasKey('average_cost', app(InventoryReadService::class)->inventories($admin)->firstOrFail()->getAttributes());
    }

    private function ownedBalance(User $user, ProductInventory $inventory): void
    {
        InventoryAllocationBalance::query()->create([
            'account_id' => app(InventoryAllocationService::class)->employeeAccount($user->employee->id)->id,
            'product_inventory_id' => $inventory->id, 'allocated_quantity' => $inventory->available_quantity,
            'reserved_quantity' => $inventory->reserved_quantity,
        ]);
    }

    private function allow(User $owner, User $employee, string $permission): void
    {
        app(EmployeePermissionOverrideService::class)->change(
            $employee->employee,
            $permission,
            EmployeePermissionEffect::Allow,
            'Focused Location Balances visibility test',
            $owner,
        );
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
