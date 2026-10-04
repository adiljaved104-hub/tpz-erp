<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\ChangeResponsibilityScope;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Responsibilities\ChangeResponsibilityScopeData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Exceptions\InvalidResponsibilityScopeException;
use App\Models\InventoryReservation;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityCombinedScopeTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_all_populated_dimensions_intersect_for_products_inventory_and_orders(): void
    {
        $f = $this->responsibilityFoundation();
        $categoryId = $f['product']->category_id;
        $otherBrand = ProductBrand::factory()->create();
        $otherCategory = ProductCategory::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['status' => true]);
        $otherPlatform = MarketplacePlatform::factory()->create();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'categoryId' => $categoryId, 'condition' => ProductCondition::New,
            'warehouseId' => $f['inventory']->warehouse_id, 'platformId' => $f['platform']->id,
            'assignStockByDefault' => true,
        ]), $f['owner']);

        $products = collect([
            Product::factory()->create(['brand_id' => $f['brand']->id, 'category_id' => $categoryId, 'condition' => ProductCondition::New]),
            Product::factory()->create(['brand_id' => $otherBrand->id, 'category_id' => $categoryId, 'condition' => ProductCondition::New]),
            Product::factory()->create(['brand_id' => $f['brand']->id, 'category_id' => $otherCategory->id, 'condition' => ProductCondition::New]),
            Product::factory()->create(['brand_id' => $f['brand']->id, 'category_id' => $categoryId, 'condition' => ProductCondition::Renewed]),
        ]);
        $inventories = $products->map(fn (Product $product): ProductInventory => ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $f['inventory']->warehouse_id,
        ]));
        $wrongWarehouse = ProductInventory::factory()->create(['product_id' => $products->first()->id, 'warehouse_id' => $otherWarehouse->id]);
        $physical = app(ResponsibilityProductScopeService::class);
        $orders = app(OrderResponsibilityScopeService::class);
        foreach ($products as $index => $product) {
            $this->assertSame($index === 0, $physical->canAccessProduct($f['employee']->user, $product->id));
            $this->assertSame($index === 0, $physical->canAccessInventory($f['employee']->user, $inventories[$index]->id));
            $this->assertSame($index === 0, $orders->canAccessProduct($f['employee']->user, $product->id, $f['platform']->id, $f['inventory']->warehouse_id));
        }
        $this->assertFalse($physical->canAccessInventory($f['employee']->user, $wrongWarehouse->id));
        $this->assertFalse($orders->canAccessProduct($f['employee']->user, $products->first()->id, $otherPlatform->id, $f['inventory']->warehouse_id));
        $this->assertFalse($orders->canAccessProduct($f['employee']->user, $products->first()->id, $f['platform']->id, $otherWarehouse->id));
        $this->assertEqualsCanonicalizing([$f['inventory']->id, $inventories->first()->id], app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->pluck('inventory_id')->all());
        $this->assertSame($f['employee']->id, app(InventoryAllocationPolicyService::class)->receiptAccount($inventories->first(), null)[0]->employee_id);
        foreach ($inventories->skip(1)->push($wrongWarehouse) as $inventory) {
            try {
                app(InventoryAllocationPolicyService::class)->receiptAccount($inventory, null);
                $this->fail('An unmatched receipt must not select this default holder.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('No default stock responsibility', $exception->getMessage());
            }
        }
    }

    public function test_product_condition_and_warehouse_do_not_bypass_other_dimensions(): void
    {
        $f = $this->responsibilityFoundation();
        $other = Product::factory()->create(['brand_id' => $f['brand']->id, 'category_id' => $f['product']->category_id]);
        $otherInventory = ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id]);
        $wrongWarehouse = ProductInventory::factory()->create(['product_id' => $f['product']->id, 'warehouse_id' => Warehouse::factory()->create()->id]);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'productId' => $f['product']->id, 'categoryId' => $f['product']->category_id,
            'condition' => ProductCondition::New, 'warehouseId' => $f['inventory']->warehouse_id,
        ]), $f['owner']);
        $physical = app(ResponsibilityProductScopeService::class);
        $orders = app(OrderResponsibilityScopeService::class);
        $this->assertTrue($physical->canAccessProduct($f['employee']->user, $f['product']->id));
        $this->assertTrue($physical->canAccessInventory($f['employee']->user, $f['inventory']->id));
        $this->assertFalse($physical->canAccessProduct($f['employee']->user, $other->id));
        $this->assertFalse($physical->canAccessInventory($f['employee']->user, $otherInventory->id));
        $this->assertFalse($physical->canAccessInventory($f['employee']->user, $wrongWarehouse->id));
        $this->assertFalse($orders->canAccessProduct($f['employee']->user, $other->id, null, $f['inventory']->warehouse_id));
        $this->assertEquals([$f['inventory']->id], app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->pluck('inventory_id')->all());
        $f['product']->forceFill(['condition' => ProductCondition::Renewed])->save();
        $this->assertFalse($physical->canAccessProduct($f['employee']->user, $f['product']->id));
        $this->assertFalse($physical->canAccessInventory($f['employee']->user, $f['inventory']->id));
        $this->assertFalse($orders->canAccessProduct($f['employee']->user, $f['product']->id, null, $f['inventory']->warehouse_id));
        $this->assertCount(0, app(ResponsibilityReadService::class)->myInventory($f['employee']->user));
    }

    public function test_product_brand_and_category_mismatches_are_readable_validation(): void
    {
        $f = $this->responsibilityFoundation();
        foreach ([['brandId' => ProductBrand::factory()->create()->id], ['categoryId' => ProductCategory::factory()->create()->id]] as $mismatch) {
            try {
                app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $mismatch + ['productId' => $f['product']->id]), $f['owner']);
                $this->fail('A contradictory Product scope must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('product_id', $exception->errors());
                $this->assertStringContainsString('does not belong to the selected', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('responsibility_assignments', 0);
    }

    public function test_scope_assignments_still_cannot_contain_quantity(): void
    {
        $f = $this->responsibilityFoundation();
        $this->expectException(InvalidResponsibilityScopeException::class);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['assignedQuantity' => 1]), $f['owner']);
    }

    public function test_new_to_renewed_is_versioned_and_never_moves_existing_stock(): void
    {
        $f = $this->responsibilityFoundation(20, 1);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'categoryId' => $f['product']->category_id, 'condition' => ProductCondition::New,
            'platformId' => $f['platform']->id, 'assignStockByDefault' => true,
        ]), $f['owner']);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($f['employee']->id), 5, $f['owner'], 'Existing owned stock');
        InventoryReservation::factory()->create(['product_inventory_id' => $f['inventory']->id, 'reserved_by_user_id' => $f['owner']->id]);
        $before = $this->stockSnapshot();
        $data = $this->renewedData($f);
        $this->assertStringStartsWith('SAFE', app(ResponsibilityAssignmentService::class)->previewScopeChange($source, $data));
        $successor = app(ChangeResponsibilityScope::class)->handle($source, $data, $f['owner']);
        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $source->refresh()->status);
        $this->assertNotNull($source->ended_at);
        $this->assertSame($source->id, $successor->predecessor_assignment_id);
        $this->assertSame(ProductCondition::New, $source->conditionScope->product_condition);
        $this->assertSame(ProductCondition::Renewed, $successor->conditionScope->product_condition);
        $this->assertTrue($successor->assign_stock_by_default);
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertDatabaseHas('activity_logs', ['event' => 'responsibility.scope_changed', 'subject_id' => $successor->id]);
    }

    public function test_renewed_conflict_preserves_the_entire_existing_assignment(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'categoryId' => $f['product']->category_id, 'condition' => ProductCondition::New, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $other->employee->id, 'categoryId' => $f['product']->category_id,
            'condition' => ProductCondition::Renewed, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $before = $this->assignmentSnapshot();
        $data = $this->renewedData($f);
        $this->assertStringContainsString($other->employee->name, app(ResponsibilityAssignmentService::class)->previewScopeChange($source, $data));
        try {
            app(ChangeResponsibilityScope::class)->handle($source, $data, $f['owner']);
            $this->fail('Another active Renewed handler must block the change.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($other->employee->name, $exception->getMessage());
        }
        $this->assertSame($before, $this->assignmentSnapshot());
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
    }

    public function test_audit_failure_rolls_back_predecessor_successor_and_scope_pivots(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'categoryId' => $f['product']->category_id, 'condition' => ProductCondition::New,
        ]), $f['owner']);
        $before = $this->assignmentSnapshot();
        $this->mock(ActivityLogger::class)->shouldReceive('log')->once()->andReturnUsing(function (): never {
            throw new RuntimeException('Test audit write failure');
        });
        try {
            app(ChangeResponsibilityScope::class)->handle($source, $this->renewedData($f), $f['owner']);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test audit write failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->assignmentSnapshot());
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
    }

    private function renewedData(array $f): ChangeResponsibilityScopeData
    {
        return new ChangeResponsibilityScopeData(
            $f['employee']->id, $f['brand']->id, null, $f['product']->category_id, ProductCondition::Renewed,
            null, $f['platform']->id, true, 'New to Renewed', (string) Str::uuid(),
        );
    }

    private function stockSnapshot(): array
    {
        return $this->snapshot(['product_inventories', 'inventory_allocation_balances', 'inventory_allocation_events', 'inventory_reservations', 'inventory_allocation_reservation_lines', 'stock_movements']);
    }

    private function assignmentSnapshot(): array
    {
        return $this->snapshot(['responsibility_assignments', 'responsibility_assignment_brands', 'responsibility_assignment_categories', 'responsibility_assignment_products', 'responsibility_assignment_conditions', 'responsibility_assignment_platforms', 'responsibility_assignment_warehouses', 'activity_logs']);
    }

    private function snapshot(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
