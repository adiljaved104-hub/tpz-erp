<?php

namespace Tests\Feature\Inventory;

use App\Contracts\InventoryPermissionResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ProductInventories\Pages\ViewProductInventory;
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
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Dashboard\DashboardInventoryIntelligenceService;
use App\Services\Dashboard\ErpDashboardService;
use App\Services\Inventory\InventoryAllocationBreakdownReadService;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryReadService;
use App\Services\Reports\ReportExportService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function test_record_policy_uses_page_permissions_and_employee_ownership(): void
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

    public function test_active_warehouse_responsibility_reads_physical_stock_and_ledger_breakdown_without_changing_my_inventory(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $otherEmployee = $this->user(EmployeeRole::Staff);
        $brandA = ProductBrand::factory()->create();
        $brandB = ProductBrand::factory()->create();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $inactiveWarehouse = Warehouse::factory()->create();
        $unassignedWarehouse = Warehouse::factory()->create();

        $productA = Product::factory()->create(['brand_id' => $brandA->id]);
        $productB = Product::factory()->create(['brand_id' => $brandB->id]);
        $productC = Product::factory()->create(['brand_id' => $brandA->id]);
        $productD = Product::factory()->create(['brand_id' => $brandA->id]);
        $inA = ProductInventory::factory()->create(['product_id' => $productA->id, 'warehouse_id' => $warehouseA->id, 'available_quantity' => 10, 'reserved_quantity' => 3, 'average_cost' => '125.0000']);
        $zeroInA = ProductInventory::factory()->create(['product_id' => $productC->id, 'warehouse_id' => $warehouseA->id, 'available_quantity' => 0, 'reserved_quantity' => 0]);
        $wrongBrandInA = ProductInventory::factory()->create(['product_id' => $productB->id, 'warehouse_id' => $warehouseA->id, 'available_quantity' => 5, 'reserved_quantity' => 0]);
        $inB = ProductInventory::factory()->create(['product_id' => $productB->id, 'warehouse_id' => $warehouseB->id, 'available_quantity' => 4, 'reserved_quantity' => 1]);
        $inactive = ProductInventory::factory()->create(['product_id' => Product::factory()->create(['brand_id' => $brandA->id])->id, 'warehouse_id' => $inactiveWarehouse->id, 'available_quantity' => 6, 'reserved_quantity' => 0]);
        $unassigned = ProductInventory::factory()->create(['product_id' => $productD->id, 'warehouse_id' => $unassignedWarehouse->id, 'available_quantity' => 8, 'reserved_quantity' => 0]);

        $this->warehouseAssignment($staff, $warehouseA, $brandA);
        $this->warehouseAssignment($staff, $warehouseB, $brandB);
        $this->warehouseAssignment($staff, $inactiveWarehouse, $brandA, active: false);
        foreach ([InventoryPermission::View->value, InventoryPermission::ViewLocationBalances->value, InventoryPermission::Export->value] as $permission) {
            $this->allow($owner, $staff, $permission);
        }

        $allocation = app(InventoryAllocationService::class);
        $this->allocationBalance($allocation->employeeAccount($staff->employee->id)->id, $inA, 4, 1);
        $this->allocationBalance($allocation->employeeAccount($otherEmployee->employee->id)->id, $inA, 3, 1);
        $this->allocationBalance($allocation->systemAccount()->id, $inA, 3, 1);
        $this->allocationBalance($allocation->employeeAccount($staff->employee->id)->id, $inB, 1, 0);
        $ledgerBeforeReads = InventoryAllocationBalance::query()->orderBy('id')->get()->toArray();

        $this->actingAs($staff->fresh());
        $query = ProductInventoryResource::getEloquentQuery()->get()->keyBy('id');
        $this->assertEqualsCanonicalizing([$inA->id, $zeroInA->id, $inB->id], $query->keys()->all());
        $this->assertSame(10, $query[$inA->id]->available_quantity);
        $this->assertSame(10, $query[$inA->id]->allocated_quantity);
        $this->assertSame(3, $query[$inA->id]->reserved_quantity);
        $this->assertSame(7, $query[$inA->id]->sellableQuantity());
        $this->assertSame(1, $query[$inA->id]->warehouse_responsibility_visible);
        $this->assertSame(4, $query[$inB->id]->available_quantity);
        $this->assertSame(1, $query[$inB->id]->allocated_quantity);
        $this->assertSame(0, $query[$zeroInA->id]->available_quantity);
        $this->assertSame(0, $query[$zeroInA->id]->allocated_quantity);

        Livewire::test(ListProductInventories::class)
            ->assertOk()->assertCanSeeTableRecords([$inA, $zeroInA, $inB])
            ->assertCanNotSeeTableRecords([$wrongBrandInA, $inactive, $unassigned]);
        $this->assertSame(0, ProductInventoryResource::getEloquentQuery()->where('product_inventories.warehouse_id', $unassignedWarehouse->id)->count());
        Livewire::test(ListProductInventories::class)->filterTable('warehouse_id', $warehouseA->id)
            ->assertCanSeeTableRecords([$inA, $zeroInA])->assertCanNotSeeTableRecords([$wrongBrandInA, $inB, $inactive, $unassigned]);
        Livewire::test(ListProductInventories::class)->filterTable('warehouse_id', $unassignedWarehouse->id)
            ->assertCanNotSeeTableRecords([$wrongBrandInA, $inactive, $unassigned]);
        Livewire::test(ListProductInventories::class)->searchTable($wrongBrandInA->product->sku)
            ->assertCanSeeTableRecords([$inB])->assertCanNotSeeTableRecords([$wrongBrandInA]);
        Livewire::test(ListProductInventories::class)->filterTable('warehouse_id', $warehouseA->id)
            ->filterTable('product_id', $productB->id)->assertCountTableRecords(0);
        foreach ([$wrongBrandInA, $inactive, $unassigned] as $blockedRecord) {
            try {
                Livewire::test(ViewProductInventory::class, ['record' => $blockedRecord->id]);
                $this->fail('Warehouse details outside the active assignment must not resolve by direct record URL.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        Livewire::test(ViewProductInventory::class, ['record' => $inA->id])
            ->assertOk()->assertSee('Allocation Breakdown')->assertSee('Reconciled')
            ->assertSee($staff->employee->employee_id)->assertSee($otherEmployee->employee->employee_id)
            ->assertSee('System / Unallocated')->assertSee('Total Stock 10')->assertSee('Sellable 7');

        $breakdown = app(InventoryAllocationBreakdownReadService::class)->forInventory($staff, $inA);
        $this->assertNotNull($breakdown);
        $this->assertTrue($breakdown['reconciled']);
        $this->assertSame(10, $breakdown['total_stock']);
        $this->assertSame(10, $breakdown['allocated']);
        $this->assertSame(3, $breakdown['reserved']);
        $this->assertSame(7, $breakdown['sellable']);
        $this->assertFalse(app(InventoryAuthorization::class)->allows($staff, InventoryPermission::ViewAllocations));
        $this->assertSame(5, app(ResponsibilityReadService::class)->myInventory($staff)->sum('my_allocated'));
        $this->assertFalse(app(InventoryAuthorization::class)->allows($staff, InventoryPermission::Reserve, $inA));
        $this->assertArrayNotHasKey('average_cost', $query[$inA->id]->getAttributes());
        $this->assertArrayNotHasKey('inventory_value', $pageColumns = Livewire::test(ListProductInventories::class)->instance()->getTable()->getColumns());
        $this->assertArrayNotHasKey('average_cost', $pageColumns);
        $exportScopeIds = app(ResponsibilityProductScopeService::class)->inventoryIds($staff)->pluck('id')->all();
        $this->assertContains($inA->id, $exportScopeIds);
        $this->assertContains($inB->id, $exportScopeIds);
        $this->assertNotContains($wrongBrandInA->id, $exportScopeIds);
        $this->assertNotContains($inactive->id, $exportScopeIds);
        $this->assertNotContains($unassigned->id, $exportScopeIds);

        $export = app(ReportExportService::class)->export($staff, 'current_inventory', 'csv', ['warehouse_id' => $warehouseA->id]);
        ob_start();
        $export->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString($productA->sku, $csv);
        $this->assertStringContainsString($productC->sku, $csv);
        $this->assertStringNotContainsString($productB->sku, $csv);
        $this->assertStringNotContainsString($productD->sku, $csv);
        $this->assertStringNotContainsString('125.0000', $csv);
        $this->assertSame($ledgerBeforeReads, InventoryAllocationBalance::query()->orderBy('id')->get()->toArray());
    }

    public function test_warehouse_allocation_discrepancy_is_reported_without_inventing_system_stock(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $inventory = ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'available_quantity' => 9, 'reserved_quantity' => 2]);
        $this->warehouseAssignment($staff, $warehouse);
        foreach ([InventoryPermission::View->value, InventoryPermission::ViewLocationBalances->value] as $permission) {
            $this->allow($owner, $staff, $permission);
        }
        $account = app(InventoryAllocationService::class)->employeeAccount($staff->employee->id);
        $this->allocationBalance($account->id, $inventory, 7, 1);
        $this->actingAs($staff->fresh());

        Livewire::test(ListProductInventories::class)->assertOk()->assertCanSeeTableRecords([$inventory]);
        Livewire::test(ViewProductInventory::class, ['record' => $inventory->id])
            ->assertOk()->assertSee('Allocation Breakdown')->assertDontSee('Average Cost')->assertDontSee('Inventory Value');

        $result = app(InventoryAllocationBreakdownReadService::class)->forInventory($staff, $inventory);

        $this->assertNotNull($result);
        $this->assertFalse($result['reconciled']);
        $this->assertSame(2, $result['allocation_gap']);
        $this->assertSame(1, $result['reserved_gap']);
        $this->assertSame(0, $result['lines']->firstWhere('is_system', true)['allocated']);
        $this->assertStringContainsString('No allocation is inferred', app(InventoryAllocationBreakdownReadService::class)->reconciliationText($staff, $inventory));
    }

    public function test_warehouse_assignment_does_not_replace_stock_by_location_permissions_or_financial_permissions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $warehouse = Warehouse::factory()->create();
        $inventory = ProductInventory::factory()->create(['warehouse_id' => $warehouse->id]);
        $this->warehouseAssignment($staff, $warehouse);
        $this->allow($owner, $staff, InventoryPermission::ViewLocationBalances->value);
        $this->actingAs($staff->fresh());

        $this->assertFalse(ProductInventoryResource::canViewAny());
        $this->assertSame(0, ProductInventoryResource::getEloquentQuery()->count());
        $this->assertFalse($staff->can('view', $inventory));
        $this->assertNull(app(InventoryAllocationBreakdownReadService::class)->forInventory($staff, $inventory));

        $this->allow($owner, $staff, InventoryPermission::View->value);
        $this->actingAs($staff->fresh());
        $this->assertSame([$inventory->id], ProductInventoryResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue($staff->can('view', $inventory));
        $this->assertArrayNotHasKey('average_cost', ProductInventoryResource::getEloquentQuery()->firstOrFail()->getAttributes());
        $this->assertFalse(app(InventoryAuthorization::class)->allows($staff, InventoryPermission::ViewFinancials));
    }

    private function ownedBalance(User $user, ProductInventory $inventory): void
    {
        InventoryAllocationBalance::query()->create([
            'account_id' => app(InventoryAllocationService::class)->employeeAccount($user->employee->id)->id,
            'product_inventory_id' => $inventory->id, 'allocated_quantity' => $inventory->available_quantity,
            'reserved_quantity' => $inventory->reserved_quantity,
        ]);
    }

    private function allocationBalance(int $accountId, ProductInventory $inventory, int $allocated, int $reserved): void
    {
        InventoryAllocationBalance::query()->create([
            'account_id' => $accountId,
            'product_inventory_id' => $inventory->id,
            'allocated_quantity' => $allocated,
            'reserved_quantity' => $reserved,
        ]);
    }

    private function warehouseAssignment(User $user, Warehouse $warehouse, ?ProductBrand $brand = null, bool $active = true): ResponsibilityAssignment
    {
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $user->employee->id,
            'status' => $active ? 'active' : 'inactive',
            'ended_at' => $active ? null : now(),
        ]);
        ResponsibilityAssignmentWarehouse::query()->create(['assignment_id' => $assignment->id, 'warehouse_id' => $warehouse->id]);
        if ($brand !== null) {
            ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $brand->id]);
        }

        return $assignment;
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
