<?php

namespace Tests\Feature\Responsibilities;

use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ProductMarketplaceListing;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentCategory;
use App\Models\ResponsibilityAssignmentCondition;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\ResponsibilityAssignmentWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Inventory\InventoryReadService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponsibilityPhysicalInventoryScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_and_category_platform_scopes_include_matching_physical_inventory_without_listings(): void
    {
        $platform = MarketplacePlatform::factory()->create();
        $brand = ProductBrand::factory()->create();
        $otherBrand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();
        $otherCategory = ProductCategory::factory()->create();
        $warehouse = Warehouse::factory()->create(['marketplace_platform_id' => null]);
        $matching = $this->inventory($warehouse, $brand, $category);
        $wrongBrand = $this->inventory($warehouse, $otherBrand, $category);
        $wrongCategory = $this->inventory($warehouse, $brand, $otherCategory);

        $brandUser = $this->user();
        $this->assignment($brandUser, brand: $brand, platform: $platform);
        $this->assertVisibleInventoryIds($brandUser, [$matching->id, $wrongCategory->id]);

        $categoryBrandUser = $this->user();
        $this->assignment($categoryBrandUser, brand: $brand, category: $category, platform: $platform);
        $this->assertVisibleInventoryIds($categoryBrandUser, [$matching->id]);
        $this->assertSame([$matching->id], app(InventoryReadService::class)->inventories($categoryBrandUser)->pluck('id')->all());
        $this->assertTrue(app(InventoryLocationOverviewService::class)->forUser($categoryBrandUser, false)->isEmpty());
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($matching, $owner);
        $allocation->reconcile($matching, $allocation->employeeAccount($categoryBrandUser->employee->id), 2, $owner, 'Explicit visibility fixture ownership');
        $this->assertSame(
            [$matching->product_id],
            app(InventoryLocationOverviewService::class)->forUser($categoryBrandUser, false)->pluck('product.id')->all(),
        );

        $this->assertNotContains($wrongBrand->id, app(ResponsibilityProductScopeService::class)->inventoryIds($brandUser)->pluck('id'));
    }

    public function test_brand_product_warehouse_and_condition_scopes_remain_exact(): void
    {
        $brand = ProductBrand::factory()->create();
        $otherBrand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();
        $firstWarehouse = Warehouse::factory()->create(['marketplace_platform_id' => null]);
        $secondWarehouse = Warehouse::factory()->create(['marketplace_platform_id' => null]);
        $matching = $this->inventory($firstWarehouse, $brand, $category, ProductCondition::Renewed);
        $sameBrandNew = $this->inventory($secondWarehouse, $brand, $category, ProductCondition::New);
        $otherBrandRenewed = $this->inventory($firstWarehouse, $otherBrand, $category, ProductCondition::Renewed);

        $brandUser = $this->user();
        $this->assignment($brandUser, brand: $brand);
        $this->assertVisibleInventoryIds($brandUser, [$matching->id, $sameBrandNew->id]);

        $productUser = $this->user();
        $this->assignment($productUser, product: $matching->product);
        $this->assertVisibleInventoryIds($productUser, [$matching->id]);

        $warehouseUser = $this->user();
        $this->assignment($warehouseUser, warehouse: $firstWarehouse);
        $this->assertVisibleInventoryIds($warehouseUser, [$matching->id, $otherBrandRenewed->id]);

        $conditionUser = $this->user();
        $this->assignment($conditionUser, condition: ProductCondition::Renewed);
        $this->assertVisibleInventoryIds($conditionUser, [$matching->id, $otherBrandRenewed->id]);
    }

    public function test_platform_only_scope_requires_real_platform_evidence_and_never_exposes_all_physical_inventory(): void
    {
        $platform = MarketplacePlatform::factory()->create();
        $brand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();
        $warehouse = Warehouse::factory()->create(['marketplace_platform_id' => null]);
        $listed = $this->inventory($warehouse, $brand, $category);
        $unlisted = $this->inventory($warehouse, $brand, $category);
        $user = $this->user();
        $this->assignment($user, platform: $platform);

        $this->assertVisibleInventoryIds($user, []);

        ProductMarketplaceListing::query()->create([
            'product_id' => $listed->product_id,
            'marketplace_platform_id' => $platform->id,
            'marketplace_identifier' => 'LISTED-1',
            'listing_sku' => 'LISTED-SKU-1',
            'listing_title' => $listed->product->name,
        ]);

        $this->assertVisibleInventoryIds($user, [$listed->id]);
        $this->assertNotContains($unlisted->id, app(ResponsibilityProductScopeService::class)->inventoryIds($user)->pluck('id'));
    }

    public function test_platform_warehouse_scope_remains_platform_specific_without_listing_metadata(): void
    {
        $platform = MarketplacePlatform::factory()->create();
        $otherPlatform = MarketplacePlatform::factory()->create();
        $brand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();
        $platformInventory = $this->inventory(Warehouse::factory()->create(['marketplace_platform_id' => $platform->id]), $brand, $category);
        $otherPlatformInventory = $this->inventory(Warehouse::factory()->create(['marketplace_platform_id' => $otherPlatform->id]), $brand, $category);
        $user = $this->user();
        $this->assignment($user, platform: $platform);

        $this->assertVisibleInventoryIds($user, [$platformInventory->id]);
        $this->assertNotContains($otherPlatformInventory->id, app(ResponsibilityProductScopeService::class)->inventoryIds($user)->pluck('id'));
    }

    private function assignment(
        User $user,
        ?ProductBrand $brand = null,
        ?ProductCategory $category = null,
        ?MarketplacePlatform $platform = null,
        ?Product $product = null,
        ?Warehouse $warehouse = null,
        ?ProductCondition $condition = null,
    ): ResponsibilityAssignment {
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $user->employee->id]);

        if ($brand !== null) {
            ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $brand->id]);
        }
        if ($category !== null) {
            ResponsibilityAssignmentCategory::query()->create(['assignment_id' => $assignment->id, 'product_category_id' => $category->id]);
        }
        if ($platform !== null) {
            ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $platform->id]);
        }
        if ($product !== null) {
            ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
        }
        if ($warehouse !== null) {
            ResponsibilityAssignmentWarehouse::query()->create(['assignment_id' => $assignment->id, 'warehouse_id' => $warehouse->id]);
        }
        if ($condition !== null) {
            ResponsibilityAssignmentCondition::query()->create(['assignment_id' => $assignment->id, 'product_condition' => $condition]);
        }

        return $assignment;
    }

    private function inventory(
        Warehouse $warehouse,
        ProductBrand $brand,
        ProductCategory $category,
        ProductCondition $condition = ProductCondition::New,
    ): ProductInventory {
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'condition' => $condition,
        ]);

        return ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 5,
        ]);
    }

    /** @param array<int, int> $expected */
    private function assertVisibleInventoryIds(User $user, array $expected): void
    {
        $actual = app(ResponsibilityProductScopeService::class)->inventoryIds($user)->orderBy('product_inventories.id')->pluck('id')->all();
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Staff)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
