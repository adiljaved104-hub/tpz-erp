<?php

namespace Tests\Feature\ProductIntelligence;

use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\Enums\ComponentType;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HardwareSubsystem;
use App\Enums\InventoryItemType;
use App\Enums\OrderPermission;
use App\Enums\ProductMatchClassification;
use App\Enums\ProductMatchContext;
use App\Enums\UpgradeRecipeOperation;
use App\Models\Component;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductHardwareProfile;
use App\Models\ProductInventory;
use App\Models\SalesConfiguration;
use App\Models\StockMovement;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ProductIntelligence\ProductMatchService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ProductMatchServiceTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_exact_physical_match_is_ranked_first_with_explanations(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $exact = $this->laptop('TPZ-LOQ-001', 'Lenovo LOQ 15IRX9 Core i7 13650HX 16GB 512GB RTX 4060', [
            'brand' => 'Lenovo', 'model' => 'LOQ 15IRX9', 'processor' => 'Core i7 13650HX',
            'ram' => '16GB', 'storage' => '512GB NVMe', 'graphics' => 'RTX 4060',
        ]);
        $this->laptop('TPZ-TUF-001', 'ASUS TUF Core i7 16GB 512GB RTX 4050', [
            'brand' => 'ASUS', 'model' => 'TUF', 'processor' => 'Core i7 13620H',
            'ram' => '16GB', 'storage' => '512GB NVMe', 'graphics' => 'RTX 4050',
        ]);
        ProductInventory::factory()->create(['product_id' => $exact->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 3]);

        $results = $this->match($owner, 'Lenovo LOQ 15IRX9 i7 13650HX 16GB 512GB RTX 4060', ProductMatchContext::Order, $warehouse);

        $this->assertNotEmpty($results);
        $this->assertSame($exact->id, $results->first()->productId);
        $this->assertContains($results->first()->classification, [ProductMatchClassification::Exact, ProductMatchClassification::VeryHigh]);
        $this->assertSame(3, $results->first()->sellableQuantity);
        $this->assertNotEmpty($results->first()->reasons);
    }

    public function test_cpu_and_gpu_conflicts_cannot_be_presented_as_exact(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $product = $this->laptop('TPZ-CONFLICT', 'Lenovo LOQ Core i5 16GB 512GB RTX 4050', [
            'brand' => 'Lenovo', 'model' => 'LOQ', 'processor' => 'Core i5 13450HX',
            'ram' => '16GB', 'storage' => '512GB NVMe', 'graphics' => 'RTX 4050',
        ]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 2]);

        $results = $this->match($owner, 'Lenovo LOQ Core i9 14900HX 16GB 512GB RTX 4090', ProductMatchContext::Order, $warehouse);
        $result = $results->first(fn ($result): bool => $result->productId === $product->id);

        if ($result !== null) {
            $this->assertNotContains($result->classification, [ProductMatchClassification::Exact, ProductMatchClassification::VeryHigh]);
            $this->assertTrue(collect($result->reasons)->contains(fn ($reason): bool => $reason->kind === 'conflict'));
        } else {
            $this->assertFalse($results->contains(fn ($result): bool => $result->productId === $product->id));
        }
    }

    public function test_product_creation_reports_a_probable_duplicate_without_mutation(): void
    {
        $owner = $this->owner();
        $product = $this->laptop('TPZ-DUP-001', 'HP EliteBook 840 G10 Core i7 16GB 512GB', [
            'brand' => 'HP', 'model' => 'EliteBook 840 G10', 'processor' => 'Core i7 1355U',
            'ram' => '16GB', 'storage' => '512GB NVMe', 'graphics' => 'Intel Iris Xe',
        ]);
        $beforeProducts = Product::query()->count();
        $beforeMovements = StockMovement::query()->count();

        $results = $this->match(
            $owner,
            'HP EliteBook 840 G10 i7-1355U 16GB 512GB Intel Iris Xe',
            ProductMatchContext::ProductCreation,
            attributes: ['brand' => 'HP', 'model' => 'EliteBook 840 G10', 'processor' => 'Core i7 1355U', 'ram' => '16GB', 'storage' => '512GB NVMe', 'graphics' => 'Intel Iris Xe'],
        );

        $this->assertSame($product->id, $results->first()?->productId);
        $this->assertContains($results->first()?->classification, [ProductMatchClassification::Exact, ProductMatchClassification::VeryHigh, ProductMatchClassification::PossibleDuplicate]);
        $this->assertSame($beforeProducts, Product::query()->count());
        $this->assertSame($beforeMovements, StockMovement::query()->count());
    }

    public function test_components_are_excluded_from_sales_and_included_for_purchase_search(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $componentProduct = Product::factory()->create([
            'sku' => 'RAM-16-DDR4', 'inventory_item_type' => InventoryItemType::Component,
            'name' => 'Memory Module', 'ram' => null,
        ]);
        Component::factory()->create(['product_id' => $componentProduct->id, 'specification' => '16GB DDR4 3200']);
        ProductInventory::factory()->create(['product_id' => $componentProduct->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 20]);

        $sales = $this->match($owner, '16GB DDR4 3200 RAM', ProductMatchContext::Order, $warehouse);
        $purchase = $this->match($owner, '16GB DDR4 3200 RAM', ProductMatchContext::Purchase, $warehouse);

        $this->assertFalse($sales->contains(fn ($result): bool => $result->productId === $componentProduct->id));
        $this->assertTrue($purchase->contains(fn ($result): bool => $result->productId === $componentProduct->id));
        $this->assertStringContainsString('16GB', collect($purchase->firstWhere('productId', $componentProduct->id)?->reasons)->pluck('message')->implode(' '));
    }

    public function test_order_search_requires_sellable_stock_and_is_read_only(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $available = $this->laptop('TPZ-STOCK-YES', 'Dell Latitude 7440 i7 16GB 512GB', ['brand' => 'Dell', 'model' => 'Latitude 7440', 'processor' => 'Core i7 1365U', 'ram' => '16GB', 'storage' => '512GB NVMe']);
        $unavailable = $this->laptop('TPZ-STOCK-NO', 'Dell Latitude 7440 i7 32GB 1TB', ['brand' => 'Dell', 'model' => 'Latitude 7440', 'processor' => 'Core i7 1365U', 'ram' => '32GB', 'storage' => '1TB NVMe']);
        ProductInventory::factory()->create(['product_id' => $available->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 2, 'reserved_quantity' => 1]);
        ProductInventory::factory()->create(['product_id' => $unavailable->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 1, 'reserved_quantity' => 1]);
        $before = ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'average_cost'])->all();

        $results = $this->match($owner, 'Dell Latitude 7440', ProductMatchContext::Order, $warehouse);

        $this->assertTrue($results->contains(fn ($result): bool => $result->productId === $available->id));
        $this->assertFalse($results->contains(fn ($result): bool => $result->productId === $unavailable->id));
        $this->assertSame($before, ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'average_cost'])->all());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_search_queries_omit_financial_and_recovery_fields(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $product = $this->laptop('TPZ-SQL-001', 'HP ProBook 450 G10 i5 16GB 512GB', ['brand' => 'HP', 'model' => 'ProBook 450 G10', 'processor' => 'Core i5 1335U', 'ram' => '16GB', 'storage' => '512GB NVMe']);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 1, 'average_cost' => 2400]);
        $component = Component::factory()->create([
            'specification' => '32GB DDR5 5600 Memory',
            'approved_oem_recovery_value' => 45,
            'recovery_approved_by_user_id' => $owner->id,
            'recovery_approved_at' => now(),
            'recovery_reason' => 'Approved test recovery value.',
        ]);
        DB::enableQueryLog();

        $service = app(ProductMatchService::class);
        $service->match(new ProductMatchRequest('HP ProBook 450 G10 16GB 512GB', ProductMatchContext::Order, $owner, $warehouse->id));
        $service->match(new ProductMatchRequest('32GB DDR5 5600 Memory', ProductMatchContext::Purchase, $owner, $warehouse->id));

        $sql = mb_strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
        foreach (['cost_price', 'average_cost', 'suggested_selling_addon', 'default_selling_price', 'labour_unit_cost', 'approved_oem_recovery_value', 'recovery_value_override'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
        $this->assertStringNotContainsString((string) $component->approved_oem_recovery_value, collect($service->match(new ProductMatchRequest('32GB DDR5 5600 Memory', ProductMatchContext::Purchase, $owner, $warehouse->id)))->map->compactLabel()->implode(' '));
        $this->assertLessThanOrEqual((int) config('product_matching.query_budget', 12), $service->lastMetrics()['query_count']);
    }

    public function test_candidate_relationship_loading_remains_bounded_as_catalog_grows(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        Product::factory()->count(25)->create()->each(function (Product $product) use ($warehouse): void {
            ProductInventory::factory()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'available_quantity' => 1,
            ]);
        });
        DB::enableQueryLog();

        $this->match($owner, 'Laptop', ProductMatchContext::Order, $warehouse);

        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
    }

    public function test_valid_current_configuration_is_reported_as_buildable_without_side_effects(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $baseRam = Component::factory()->create(['component_type' => ComponentType::Ram, 'specification' => '8GB DDR4 3200', 'capacity_value' => 8, 'capacity_unit' => 'gb', 'interface_type' => 'DDR4']);
        $upgradeRam = Component::factory()->create(['component_type' => ComponentType::Ram, 'specification' => '8GB DDR4 3200 Upgrade', 'capacity_value' => 8, 'capacity_unit' => 'gb', 'interface_type' => 'DDR4']);
        $product = $this->laptop('TPZ-T14-G2', 'Lenovo ThinkPad T14 Gen 2 Core i5 8GB 512GB', [
            'brand' => 'Lenovo', 'model' => 'T14 Gen 2', 'processor' => 'Core i5 1135G7', 'ram' => '8GB', 'storage' => '512GB NVMe',
        ]);
        $profile = ProductHardwareProfile::query()->create([
            'product_id' => $product->id, 'profile_version' => 1, 'ram_upgradeable' => true,
            'max_supported_ram_mb' => 32768, 'storage_upgradeable' => false,
            'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ]);
        $profile->slots()->create(['subsystem' => HardwareSubsystem::Ram, 'slot_key' => 'RAM-1', 'interface_type' => 'DDR4', 'is_soldered' => false, 'is_occupied' => true, 'base_component_id' => $baseRam->id, 'base_capacity_value' => 8, 'base_capacity_unit' => 'gb', 'position' => 1]);
        $profile->slots()->create(['subsystem' => HardwareSubsystem::Ram, 'slot_key' => 'RAM-2', 'interface_type' => 'DDR4', 'is_soldered' => false, 'is_occupied' => false, 'position' => 2]);
        $configuration = SalesConfiguration::query()->create([
            'product_id' => $product->id, 'hardware_profile_version' => 1, 'display_name' => '16GB / 512GB',
            'target_ram_mb' => 16384, 'target_storage_total_gb' => null, 'target_storage_layout' => null,
            'suggested_selling_addon' => 100, 'active' => true,
            'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ]);
        $recipe = UpgradeRecipe::query()->create([
            'sales_configuration_id' => $configuration->id, 'hardware_profile_version' => 1,
            'name' => 'Add 8GB RAM', 'preferred' => true, 'priority' => 1, 'labour_unit_cost' => 10,
            'active' => true, 'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ]);
        $recipe->lines()->create(['sequence' => 1, 'operation' => UpgradeRecipeOperation::Keep, 'source_slot_key' => 'RAM-1', 'quantity_per_laptop' => 1]);
        $recipe->lines()->create(['sequence' => 2, 'operation' => UpgradeRecipeOperation::Install, 'target_slot_key' => 'RAM-2', 'install_component_id' => $upgradeRam->id, 'quantity_per_laptop' => 1]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4]);
        ProductInventory::factory()->create(['product_id' => $upgradeRam->product_id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 2]);
        $beforeInventory = ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'average_cost'])->all();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->match($owner, 'Lenovo T14 Gen 2 i5 1135G7 16GB 512 SSD', ProductMatchContext::Order, $warehouse)
            ->first(fn ($result): bool => $result->productId === $product->id);

        $this->assertNotNull($result);
        $this->assertSame(ProductMatchClassification::BuildableConfiguration, $result->classification);
        $this->assertSame($configuration->id, $result->salesConfigurationId);
        $this->assertSame($recipe->id, $result->upgradeRecipeId);
        $this->assertSame(2, $result->buildableQuantity);
        $this->assertSame($beforeInventory, ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'average_cost'])->all());
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertSame(0, StockMovement::query()->count());
        $sql = mb_strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
        foreach (['cost_price', 'average_cost', 'suggested_selling_addon', 'default_selling_price', 'labour_unit_cost', 'approved_oem_recovery_value', 'recovery_value_override'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }

        ProductInventory::query()->where('product_id', $upgradeRam->product_id)->update(['available_quantity' => 0]);
        $unavailableBuild = $this->match($owner, 'Lenovo T14 Gen 2 i5 1135G7 16GB 512 SSD', ProductMatchContext::Order, $warehouse)
            ->first(fn ($candidate): bool => $candidate->productId === $product->id);
        $this->assertSame($configuration->id, $unavailableBuild?->salesConfigurationId);
        $this->assertSame(0, $unavailableBuild?->buildableQuantity);
        $this->assertTrue(collect($unavailableBuild?->reasons)->contains(fn ($reason): bool => str_contains($reason->message, 'required stock is unavailable')));

        $configuration->forceFill(['hardware_profile_version' => 99])->save();
        $stale = $this->match($owner, 'Lenovo T14 Gen 2 i5 1135G7 16GB 512 SSD', ProductMatchContext::Order, $warehouse)
            ->first(fn ($candidate): bool => $candidate->productId === $product->id);
        $this->assertNull($stale?->salesConfigurationId);
        $this->assertNotSame(ProductMatchClassification::BuildableConfiguration, $stale?->classification);
    }

    public function test_inactive_employee_cannot_search(): void
    {
        $owner = $this->owner();
        $owner->employee->forceFill(['status' => false])->save();
        $this->laptop('TPZ-HIDDEN', 'Lenovo ThinkPad T14', ['brand' => 'Lenovo', 'model' => 'ThinkPad T14']);

        $this->assertCount(0, $this->match($owner->refresh(), 'ThinkPad T14', ProductMatchContext::ProductCreation));
    }

    public function test_responsibility_scopes_results_but_never_grants_denied_permission(): void
    {
        $foundation = $this->responsibilityFoundation(5);
        $staff = $foundation['employee']->user;
        $warehouse = $foundation['inventory']->warehouse;
        $foundation['product']->forceFill(['name' => 'HP Scoped Laptop', 'model' => '840 G8'])->save();
        $otherBrand = ProductBrand::factory()->create(['name' => 'Dell', 'normalized_name' => 'dell']);
        $outside = Product::factory()->create(['name' => 'Dell Outside Laptop', 'brand' => 'Dell', 'brand_id' => $otherBrand->id, 'model' => '5420']);
        ProductInventory::factory()->create(['product_id' => $outside->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5]);
        app(EmployeePermissionOverrideService::class)->change(
            $foundation['employee'],
            OrderPermission::Create->value,
            EmployeePermissionEffect::Allow,
            'Search authorization regression.',
            $foundation['owner'],
        );
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);

        $this->assertTrue(app(OrderAuthorization::class)->allows($staff, OrderPermission::Create));
        $this->assertTrue(app(OrderResponsibilityScopeService::class)->canAccessProduct($staff, $foundation['product']->id, null, $warehouse->id));

        $scoped = $this->match($staff, 'Laptop', ProductMatchContext::Order, $warehouse);
        $this->assertTrue($scoped->contains(fn ($result): bool => $result->productId === $foundation['product']->id));
        $this->assertFalse($scoped->contains(fn ($result): bool => $result->productId === $outside->id));

        foreach ([OrderPermission::Create, OrderPermission::UpdateDraft] as $permission) {
            app(EmployeePermissionOverrideService::class)->change(
                $foundation['employee'],
                $permission->value,
                EmployeePermissionEffect::Deny,
                'Search authorization regression.',
                $foundation['owner'],
            );
        }

        $this->assertCount(0, $this->match($staff->refresh(), 'HP Scoped', ProductMatchContext::Order, $warehouse));
    }

    /** @param array<string, mixed> $attributes */
    private function match(User $user, string $query, ProductMatchContext $context, ?Warehouse $warehouse = null, array $attributes = [])
    {
        return app(ProductMatchService::class)->match(new ProductMatchRequest(
            query: $query,
            context: $context,
            user: $user,
            warehouseId: $warehouse?->id,
            attributes: $attributes,
            limit: 10,
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function laptop(string $sku, string $name, array $attributes): Product
    {
        $brand = (string) ($attributes['brand'] ?? 'HP');
        $brandRow = ProductBrand::query()->firstOrCreate(
            ['normalized_name' => Str::lower($brand)],
            ['name' => $brand, 'status' => true, 'created_by_user_id' => null],
        );

        return Product::factory()->create(array_merge([
            'sku' => $sku, 'name' => $name, 'brand' => $brand, 'brand_id' => $brandRow->id,
        ], $attributes));
    }

    private function owner(): User
    {
        $email = 'product-search-owner-'.Str::lower(Str::random(8)).'@example.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $email]);

        return $user->refresh();
    }
}
