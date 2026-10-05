<?php

namespace Tests\Feature\Inventory;

use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityPermission;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Pages\Inventory\MyInventory;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ProductInventories\Pages\ViewProductInventory;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ZeroStockResponsibilityVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public static function roles(): array
    {
        return [[EmployeeRole::Staff], [EmployeeRole::Manager]];
    }

    #[DataProvider('roles')]
    public function test_zero_rows_render_in_all_three_views_and_do_not_write_stock(EmployeeRole $role): void
    {
        $f = $this->foundation($role);
        $this->assign($f);
        $user = $f['employee']->user;
        $before = [ProductInventory::query()->get()->toArray(), InventoryAllocationBalance::query()->get()->toArray(),
            InventoryAllocationEvent::query()->count(), StockMovement::query()->count()];
        $row = app(ResponsibilityReadService::class)->myInventory($user)->sole();
        foreach (['available', 'reserved', 'sellable', 'my_allocated', 'my_available', 'my_reserved', 'employee_usable'] as $field) {
            $this->assertSame(0, $row->{$field});
        }
        $this->assertSame('out_of_stock', $row->stock_status);
        $this->assertNull($row->other_allocated);
        $this->assertNull($row->system_unallocated);
        $this->assertContains('Responsibility scope · Out of Stock', $row->visibility_reasons);
        Livewire::actingAs($user)->test(MyInventory::class)->assertSee($f['product']->sku)
            ->assertViewHas('summary', fn ($summary) => $summary['out'] === 1 && $summary['usable'] === 0);
        Livewire::test(InventoryOverview::class)->set('stockStatus', 'out_of_stock')->assertSee($f['product']->sku)
            ->assertSee('Responsibility scope · Out of Stock');
        Livewire::test(ListProductInventories::class)->assertCountTableRecords(1)
            ->assertTableColumnStateSet('available_quantity', 0, $f['inventory'])
            ->filterTable('stock_status', 'out_of_stock')->assertCanSeeTableRecords([$f['inventory']]);
        $this->assertTrue($user->can('view', $f['inventory']));
        Livewire::test(ViewProductInventory::class, ['record' => $f['inventory']->id])->assertOk()->assertSee($f['product']->sku);
        $this->assertSame($before, [ProductInventory::query()->get()->toArray(), InventoryAllocationBalance::query()->get()->toArray(),
            InventoryAllocationEvent::query()->count(), StockMovement::query()->count()]);
    }

    public function test_products_without_location_are_zero_in_my_inventory_overview_but_never_fabricated(): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        $this->assign($f);
        $product = Product::factory()->create(['brand_id' => $f['brand']->id]);
        $inactive = Product::factory()->create(['brand_id' => $f['brand']->id, 'status' => ProductStatus::Inactive]);
        $user = $f['employee']->user;
        $count = ProductInventory::query()->count();
        $row = app(ResponsibilityReadService::class)->myInventory($user)->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertNull($row->inventory_id);
        $this->assertNull($row->warehouse_id);
        $this->assertSame(0, $row->employee_usable);
        $overview = app(InventoryLocationOverviewService::class)->forUser($user)->firstWhere('product.id', $product->id);
        $this->assertSame(0, $overview['total_owned']);
        $this->assertTrue($overview['locations']->isEmpty());
        Livewire::actingAs($user)->test(MyInventory::class)->assertSee($product->sku)->assertSee('No current stock location')->assertDontSee($inactive->sku);
        Livewire::test(InventoryOverview::class)->assertSee($product->sku)->assertSee('No current stock location')->assertDontSee($inactive->sku);
        Livewire::test(ListProductInventories::class)->assertCountTableRecords(1);
        $this->assertSame($count, ProductInventory::query()->count());
        $this->assertFalse($product->inventories()->exists());
    }

    public function test_positive_stock_at_any_location_is_not_exposed_through_responsibility(): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        $this->assign($f);
        $positive = ProductInventory::factory()->create(['product_id' => $f['product']->id,
            'warehouse_id' => Warehouse::factory()->create()->id, 'available_quantity' => 3, 'reserved_quantity' => 0]);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($positive, $f['owner']);
        $allocation->reconcile($positive, $allocation->employeeAccount($f['owner']->employee->id), 3, $f['owner'], 'Other holder');
        $user = $f['employee']->user;
        $this->assertTrue(app(ResponsibilityReadService::class)->myInventory($user)->isEmpty());
        $this->assertTrue(app(InventoryLocationOverviewService::class)->forUser($user)->isEmpty());
        $this->assertFalse($user->can('view', $positive));
        $this->assertFalse($user->can('view', $f['inventory']));
        Livewire::actingAs($user)->test(ListProductInventories::class)->assertCountTableRecords(0);
    }

    public function test_reserved_damaged_qc_and_system_or_foreign_stock_are_masked_to_zero(): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        $this->assign($f);
        $f['inventory']->update(['available_quantity' => 3]);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocation->reconcile($f['inventory'], $allocation->employeeAccount($f['owner']->employee->id), 2, $f['owner'], 'Other holder');
        $f['inventory']->update(['reserved_quantity' => 3, 'damaged_quantity' => 7, 'qc_pending_quantity' => 5,
            'marketplace_non_sellable_quantity' => 4]);
        $user = $f['employee']->user;
        $row = app(ResponsibilityReadService::class)->myInventory($user)->sole();
        $this->assertSame(0, $row->available);
        $this->assertSame(0, $row->reserved);
        $this->assertSame(0, $row->damaged);
        $this->assertNull($row->other_allocated);
        $this->assertNull($row->system_unallocated);
        $overview = app(InventoryLocationOverviewService::class)->forUser($user)->sole();
        foreach (['available', 'reserved', 'damaged', 'qc_pending', 'marketplace_non_sellable', 'total_owned', 'in_transit'] as $key) {
            $this->assertSame(0, $overview[$key]);
        }
        $projected = app(EmployeeOwnedInventoryReadService::class)->inventories($user)->sole();
        $this->assertSame(0, $projected->available_quantity);
        $this->assertSame(0, $projected->reserved_quantity);
        Livewire::actingAs($user)->test(ListProductInventories::class)->assertTableColumnDoesNotExist('average_cost');
    }

    public function test_ending_responsibility_removes_only_placeholders_not_actual_ownership(): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        $assignment = $this->assign($f);
        $zero = Product::factory()->create(['brand_id' => $f['brand']->id]);
        $f['inventory']->update(['available_quantity' => 2]);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocation->reconcile($f['inventory'], $allocation->employeeAccount($f['employee']->id), 2, $f['owner'], 'Actual own stock');
        $user = $f['employee']->user;
        $rows = app(ResponsibilityReadService::class)->myInventory($user);
        $this->assertCount(2, $rows);
        $this->assertFalse($rows->firstWhere('product_id', $f['product']->id)->responsibility_zero_stock);
        app(ResponsibilityAssignmentService::class)->deactivate($assignment, new DeactivateResponsibilityAssignmentData('Scope no longer assigned'), $f['owner']);
        $rows = app(ResponsibilityReadService::class)->myInventory($user);
        $this->assertSame([$f['product']->id], $rows->pluck('product_id')->all());
        $this->assertSame(2, $rows->sole()->employee_usable);
        $this->assertFalse(app(InventoryLocationOverviewService::class)->forUser($user)->contains(fn ($row) => $row['product']->id === $zero->id));
        $this->assertTrue($user->can('view', $f['inventory']));
    }

    public static function scopes(): array
    {
        return array_map(fn ($scope) => [$scope], ['brand', 'category', 'condition', 'product', 'combined_platform', 'warehouse', 'quantity']);
    }

    #[DataProvider('scopes')]
    public function test_zero_stock_respects_each_existing_scope_and_keeps_platform_metadata(string $scope): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        $category = ProductCategory::factory()->create();
        $f['product']->update(['category_id' => $category->id, 'condition' => ProductCondition::Renewed]);
        $overrides = ['brandId' => null];
        $mode = ResponsibilityAssignmentMode::Scope;
        switch ($scope) {
            case 'brand': $overrides['brandId'] = $f['brand']->id;
                break;
            case 'category': $overrides['categoryId'] = $category->id;
                break;
            case 'condition': $overrides['condition'] = ProductCondition::Renewed;
                break;
            case 'product': $overrides['productId'] = $f['product']->id;
                break;
            case 'combined_platform': $overrides['brandId'] = $f['brand']->id;
                $overrides += ['categoryId' => $category->id, 'condition' => ProductCondition::Renewed, 'platformId' => $f['platform']->id];
                break;
            case 'warehouse': $overrides['warehouseId'] = $f['inventory']->warehouse_id;
                break;
            case 'quantity': $mode = ResponsibilityAssignmentMode::Quantity;
                $f['inventory']->update(['available_quantity' => 1]);
                break;
        }
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f, $mode, $overrides), $f['owner']);
        $f['inventory']->update(['available_quantity' => 0]);
        $unrelated = Product::factory()->create(['brand_id' => ProductBrand::factory()->create()->id, 'condition' => ProductCondition::New]);
        ProductInventory::factory()->create(['product_id' => $unrelated->id, 'available_quantity' => 0, 'reserved_quantity' => 0]);
        $user = $f['employee']->user;
        $rows = app(ResponsibilityReadService::class)->myInventory($user);
        $this->assertSame([$f['product']->id], $rows->pluck('product_id')->all());
        $this->assertSame(0, $rows->sole()->employee_usable);
        $this->assertSame([$f['inventory']->id], app(EmployeeOwnedInventoryReadService::class)->inventories($user)->get()->modelKeys());
        if ($scope === 'combined_platform') {
            $this->assertSame([$f['platform']->name], $rows->sole()->platforms);
        }
    }

    public function test_warehouse_scope_does_not_create_no_location_product_visibility(): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f, overrides: [
            'warehouseId' => $f['inventory']->warehouse_id,
        ]), $f['owner']);
        $missing = Product::factory()->create(['brand_id' => $f['brand']->id]);
        $user = $f['employee']->user;
        $this->assertSame([$f['product']->id], app(ResponsibilityReadService::class)->myInventory($user)->pluck('product_id')->all());
        $this->assertFalse(app(InventoryLocationOverviewService::class)->forUser($user)->contains(fn ($row) => $row['product']->id === $missing->id));
    }

    public function test_fully_reserved_owned_balance_is_not_duplicated_or_masked_as_a_placeholder(): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        $this->assign($f);
        $f['inventory']->update(['available_quantity' => 2]);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($f['inventory'], $f['owner']);
        $account = $allocation->employeeAccount($f['employee']->id);
        $allocation->reconcile($f['inventory'], $account, 2, $f['owner'], 'Own reserved stock');
        $f['inventory']->update(['reserved_quantity' => 2]);
        $account->balances()->where('product_inventory_id', $f['inventory']->id)->update(['reserved_quantity' => 2]);
        $user = $f['employee']->user;
        $row = app(ResponsibilityReadService::class)->myInventory($user)->sole();
        $this->assertFalse($row->responsibility_zero_stock);
        $this->assertSame(2, $row->my_allocated);
        $this->assertSame(2, $row->my_reserved);
        $this->assertSame(0, $row->employee_usable);
        $this->assertCount(1, app(EmployeeOwnedInventoryReadService::class)->inventories($user)->get());
    }

    public function test_owner_admin_company_views_and_employee_page_permission_gates_are_unchanged(): void
    {
        $f = $this->foundation(EmployeeRole::Staff);
        $this->assign($f);
        $f['inventory']->update(['available_quantity' => 0, 'damaged_quantity' => 3]);
        foreach ([$f['owner'], $this->responsibilityUser(EmployeeRole::Admin)] as $user) {
            $overview = app(InventoryLocationOverviewService::class)->forUser($user)->sole();
            $this->assertSame(3, $overview['damaged']);
            $this->assertSame(3, $overview['total_owned']);
            $this->assertFalse($overview['responsibility_zero_stock']);
        }
        $service = app(EmployeePermissionOverrideService::class);
        foreach ([InventoryPermission::ViewLocationBalances, InventoryLocationPermission::View, ResponsibilityPermission::ViewOwn] as $permission) {
            $service->change($f['employee'], $permission->value, EmployeePermissionEffect::Deny, null, $f['owner']);
        }
        $user = $f['employee']->user->fresh();
        Livewire::actingAs($user)->test(MyInventory::class)->assertForbidden();
        Livewire::test(InventoryOverview::class)->assertForbidden();
        Livewire::test(ListProductInventories::class)->assertForbidden();
    }

    private function foundation(EmployeeRole $role): array
    {
        $f = $this->responsibilityFoundation(0);
        $f['employee']->update(['role' => $role]);
        foreach ([InventoryPermission::View, InventoryPermission::ViewLocationBalances, InventoryLocationPermission::View] as $permission) {
            app(EmployeePermissionOverrideService::class)->change($f['employee'], $permission->value, EmployeePermissionEffect::Allow, null, $f['owner']);
        }

        return $f;
    }

    private function assign(array $f): ResponsibilityAssignment
    {
        return app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);
    }
}
