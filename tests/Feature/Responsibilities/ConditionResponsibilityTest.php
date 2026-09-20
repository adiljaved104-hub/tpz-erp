<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentBatchData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentMode;
use App\Exceptions\InvalidResponsibilityScopeException;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Responsibilities\BulkResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\ResponsibilityReadService;
use App\Services\Responsibilities\ResponsibilityScopeFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ConditionResponsibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_condition_only_is_dynamic_and_denies_the_wrong_condition(): void
    {
        $f = $this->responsibilityFoundation();
        $f['product']->forceFill(['condition' => ProductCondition::Renewed])->save();
        $matching = Product::factory()->create(['condition' => ProductCondition::Renewed]);
        $wrong = Product::factory()->create(['condition' => ProductCondition::New]);

        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);

        $scope = app(OrderResponsibilityScopeService::class);
        $productScope = app(ResponsibilityProductScopeService::class);
        $this->assertTrue($scope->canAccessProduct($f['employee']->user, $matching->id, null, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($f['employee']->user, $wrong->id, null, $f['inventory']->warehouse_id));
        $this->assertTrue($productScope->canAccessProduct($f['employee']->user, $matching->id));
        $this->assertFalse($productScope->canAccessProduct($f['employee']->user, $wrong->id));
    }

    public function test_condition_brand_and_platform_are_strict_intersections(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        $f['product']->forceFill(['condition' => ProductCondition::Renewed])->save();
        $otherBrand = ProductBrand::factory()->create();
        $otherCategory = ProductCategory::factory()->create();
        $wrongBrand = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name, 'category_id' => $category->id, 'category' => $category->name, 'condition' => ProductCondition::Renewed]);
        $wrongCondition = Product::factory()->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name, 'category_id' => $category->id, 'category' => $category->name, 'condition' => ProductCondition::New]);
        $otherPlatform = MarketplacePlatform::factory()->create();

        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => $f['brand']->id,
            'platformId' => $f['platform']->id,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);

        $scope = app(OrderResponsibilityScopeService::class);
        $user = $f['employee']->user;
        $warehouse = $f['inventory']->warehouse_id;
        $this->assertTrue($scope->canAccessProduct($user, $f['product']->id, $f['platform']->id, $warehouse));
        $this->assertFalse($scope->canAccessProduct($user, $wrongBrand->id, $f['platform']->id, $warehouse));
        $this->assertFalse($scope->canAccessProduct($user, $wrongCondition->id, $f['platform']->id, $warehouse));
        $this->assertFalse($scope->canAccessProduct($user, $f['product']->id, $otherPlatform->id, $warehouse));
    }

    public function test_condition_category_and_platform_are_strict_intersections(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        $f['product']->forceFill(['condition' => ProductCondition::Renewed])->save();
        $otherCategory = ProductCategory::factory()->create();
        $wrongCategory = Product::factory()->create(['category_id' => $otherCategory->id, 'category' => $otherCategory->name, 'condition' => ProductCondition::Renewed]);
        $wrongCondition = Product::factory()->create(['category_id' => $category->id, 'category' => $category->name, 'condition' => ProductCondition::New]);
        $otherPlatform = MarketplacePlatform::factory()->create();

        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null,
            'categoryId' => $category->id,
            'platformId' => $f['platform']->id,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);

        $scope = app(OrderResponsibilityScopeService::class);
        $user = $f['employee']->user;
        $warehouse = $f['inventory']->warehouse_id;
        $this->assertTrue($scope->canAccessProduct($user, $f['product']->id, $f['platform']->id, $warehouse));
        $this->assertFalse($scope->canAccessProduct($user, $wrongCategory->id, $f['platform']->id, $warehouse));
        $this->assertFalse($scope->canAccessProduct($user, $wrongCondition->id, $f['platform']->id, $warehouse));
        $this->assertFalse($scope->canAccessProduct($user, $f['product']->id, $otherPlatform->id, $warehouse));
    }

    public function test_all_approved_condition_scope_combinations_materialize_exact_dimensions(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        $cases = [
            ['brandId' => null],
            ['brandId' => null, 'platformId' => $f['platform']->id],
            ['brandId' => $f['brand']->id],
            ['brandId' => $f['brand']->id, 'platformId' => $f['platform']->id],
            ['brandId' => null, 'categoryId' => $category->id],
            ['brandId' => null, 'categoryId' => $category->id, 'platformId' => $f['platform']->id],
            ['brandId' => null, 'warehouseId' => $f['inventory']->warehouse_id],
            ['brandId' => null, 'warehouseId' => $f['inventory']->warehouse_id, 'platformId' => $f['platform']->id],
        ];

        foreach ($cases as $overrides) {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $overrides + [
                'condition' => ProductCondition::Renewed,
                'idempotencyKey' => (string) Str::uuid(),
            ]), $f['owner']);
        }

        $assignments = $f['employee']->responsibilityAssignments()->with(['brandScope', 'categoryScope', 'platformScope', 'warehouseScope', 'conditionScope'])->get();
        $this->assertCount(8, $assignments);
        $this->assertTrue($assignments->every(fn ($assignment): bool => $assignment->conditionScope->product_condition === ProductCondition::Renewed));
        $this->assertSame(2, $assignments->filter(fn ($assignment): bool => $assignment->brandScope !== null)->count());
        $this->assertSame(2, $assignments->filter(fn ($assignment): bool => $assignment->categoryScope !== null)->count());
        $this->assertSame(2, $assignments->filter(fn ($assignment): bool => $assignment->warehouseScope !== null)->count());
        $this->assertSame(4, $assignments->filter(fn ($assignment): bool => $assignment->platformScope !== null)->count());
    }

    public function test_condition_cannot_be_combined_with_product_quantity_or_category_brand_shapes(): void
    {
        $f = $this->responsibilityFoundation();
        $attempts = [
            [ResponsibilityAssignmentMode::Scope, ['brandId' => null, 'productId' => $f['product']->id, 'condition' => ProductCondition::Renewed]],
            [ResponsibilityAssignmentMode::Scope, ['brandId' => $f['brand']->id, 'categoryId' => $f['product']->category_id, 'condition' => ProductCondition::Renewed]],
            [ResponsibilityAssignmentMode::Quantity, ['brandId' => null, 'productInventoryId' => $f['inventory']->id, 'assignedQuantity' => 1, 'condition' => ProductCondition::Renewed]],
        ];

        foreach ($attempts as [$mode, $overrides]) {
            try {
                app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, $mode, $overrides + [
                    'idempotencyKey' => (string) Str::uuid(),
                ]), $f['owner']);
                $this->fail('The unapproved Condition scope shape must be rejected.');
            } catch (InvalidResponsibilityScopeException) {
                $this->assertDatabaseCount('responsibility_assignments', 0);
            }
        }
    }

    public function test_condition_brand_and_platform_bulk_expansion_is_exact_and_idempotent(): void
    {
        $f = $this->responsibilityFoundation();
        $brands = collect([$f['brand'], ProductBrand::factory()->create()]);
        $platforms = MarketplacePlatform::factory()->count(2)->create();
        $data = new CreateResponsibilityAssignmentBatchData(
            employeeId: $f['employee']->id,
            scopeType: 'brand',
            scopeIds: $brands->pluck('id')->all(),
            categoryId: null,
            platformId: null,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Renewed marketplace coverage',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
            platformIds: $platforms->modelKeys(),
            condition: ProductCondition::Renewed,
        );

        $created = app(BulkResponsibilityAssignmentService::class)->create($data, $f['owner']);
        $retry = app(BulkResponsibilityAssignmentService::class)->create($data, $f['owner']);

        $this->assertCount(4, $created);
        $this->assertCount(4, $retry);
        $this->assertSame(4, $created->pluck('active_fingerprint')->unique()->count());
        $this->assertTrue($created->every(fn ($assignment): bool => $assignment->conditionScope->product_condition === ProductCondition::Renewed));
        $this->assertEqualsCanonicalizing($brands->pluck('id')->all(), $created->pluck('brandScope.product_brand_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing($platforms->modelKeys(), $created->pluck('platformScope.marketplace_platform_id')->unique()->values()->all());
    }

    public function test_warehouse_condition_gates_inventory_and_preserves_quantity_precedence(): void
    {
        $f = $this->responsibilityFoundation(10);
        $f['product']->forceFill(['condition' => ProductCondition::Renewed])->save();
        $wrongProduct = Product::factory()->create(['condition' => ProductCondition::New]);
        $wrongInventory = ProductInventory::factory()->create(['product_id' => $wrongProduct->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 9]);
        $wrongWarehouse = Warehouse::factory()->create(['status' => true]);
        $matchingConditionWrongWarehouseProduct = Product::factory()->create(['condition' => ProductCondition::Renewed]);
        $matchingConditionWrongWarehouse = ProductInventory::factory()->create(['product_id' => $matchingConditionWrongWarehouseProduct->id, 'warehouse_id' => $wrongWarehouse->id, 'available_quantity' => 9]);

        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null,
            'warehouseId' => $f['inventory']->warehouse_id,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, [
            'assignedQuantity' => 1,
        ]), $f['owner']);

        $scope = app(ResponsibilityProductScopeService::class);
        $this->assertTrue($scope->canAccessInventory($f['employee']->user, $f['inventory']->id));
        $this->assertFalse($scope->canAccessInventory($f['employee']->user, $wrongInventory->id));
        $this->assertFalse($scope->canAccessInventory($f['employee']->user, $matchingConditionWrongWarehouse->id));
        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertSame('Renewed', $row->condition_label);
        $this->assertContains('Renewed · Warehouse: '.$f['inventory']->warehouse->name, $row->visibility_reasons);
        $this->assertSame(1, $row->employee_usable);
    }

    public function test_warehouse_condition_and_platform_reject_wrong_context_dimensions(): void
    {
        $f = $this->responsibilityFoundation();
        $f['product']->forceFill(['condition' => ProductCondition::Renewed])->save();
        $wrongCondition = Product::factory()->create(['condition' => ProductCondition::New]);
        $wrongPlatform = MarketplacePlatform::factory()->create();
        $wrongWarehouse = Warehouse::factory()->create(['status' => true]);

        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null,
            'warehouseId' => $f['inventory']->warehouse_id,
            'platformId' => $f['platform']->id,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);

        $scope = app(OrderResponsibilityScopeService::class);
        $user = $f['employee']->user;
        $this->assertTrue($scope->canAccessProduct($user, $f['product']->id, $f['platform']->id, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($user, $wrongCondition->id, $f['platform']->id, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($user, $f['product']->id, $wrongPlatform->id, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($user, $f['product']->id, $f['platform']->id, $wrongWarehouse->id));
    }

    public function test_condition_scope_is_enforced_during_normal_and_web_sales_order_reservation(): void
    {
        $f = $this->responsibilityFoundation(10);
        $f['product']->forceFill(['condition' => ProductCondition::Renewed])->save();
        $wrong = Product::factory()->create(['condition' => ProductCondition::New]);
        ProductInventory::factory()->create(['product_id' => $wrong->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 5]);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);

        $makeData = fn (Product $product, ?string $channel = null): SaveAndReserveOrderData => new SaveAndReserveOrderData(
            warehouseId: $f['inventory']->warehouse_id,
            platformId: null,
            externalOrderNumber: null,
            orderDate: now()->toDateString(),
            handledByEmployeeId: $f['employee']->id,
            notes: null,
            items: [new OrderItemData($product->id, 1, '500.00')],
            idempotencyKey: (string) Str::uuid(),
            webSalesChannel: $channel,
            customerName: $channel === null ? null : 'Condition Customer',
            customerPhone: $channel === null ? null : '+971500000000',
            deliveryType: $channel === null ? null : 'shop_pickup',
        );

        app(SaveAndReserveOrder::class)->handle($makeData($f['product']), $f['employee']->user);
        app(SaveAndReserveOrder::class)->handle($makeData($f['product'], 'other'), $f['employee']->user);

        try {
            app(SaveAndReserveOrder::class)->handle($makeData($wrong), $f['employee']->user);
            $this->fail('Wrong-condition inventory must be denied during Order reservation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 2);
        }
    }

    public function test_null_condition_fingerprint_is_byte_compatible_and_transfer_preserves_condition(): void
    {
        $f = $this->responsibilityFoundation();
        $fingerprints = app(ResponsibilityScopeFingerprint::class);
        $expected = hash('sha256', json_encode([
            'employee_id' => $f['employee']->id,
            'mode' => ResponsibilityAssignmentMode::Scope->value,
            'brand_id' => $f['brand']->id,
            'platform_id' => null,
            'product_id' => null,
            'product_inventory_id' => null,
        ], JSON_THROW_ON_ERROR));
        $this->assertSame($expected, $fingerprints->make($f['employee']->id, ResponsibilityAssignmentMode::Scope, $f['brand']->id, null, null, null));

        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $successor = app(TransferResponsibilityAssignment::class)->handle($source, new TransferResponsibilityAssignmentData(
            $destination->employee->id,
            'Condition handover',
            (string) Str::uuid(),
        ), $f['owner']);

        $this->assertSame(ProductCondition::Renewed, $successor->conditionScope->product_condition);
        $this->assertDatabaseHas('activity_logs', ['event' => 'responsibility.transferred', 'subject_id' => $successor->id]);
    }
}
