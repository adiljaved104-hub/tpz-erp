<?php

namespace Tests\Feature\Orders;

use App\Enums\EmployeeRole;
use App\Enums\ProductMatchContext;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ProductIntelligence\ProductSearchOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OrderStockVisibilityDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_platform_responsibility_and_sellable_stock_show_product(): void
    {
        $fixture = $this->fixture();
        $this->grantBrandPlatformScope($fixture);

        $results = $this->search($fixture['staff'], $fixture['product'], $fixture['warehouse'], $fixture['platform']);

        $this->assertArrayHasKey($fixture['product']->id, $results);
    }

    public function test_create_order_product_selector_uses_selected_platform_and_warehouse(): void
    {
        $fixture = $this->fixture();
        $this->grantBrandPlatformScope($fixture);

        $component = Livewire::actingAs($fixture['staff'])->test(CreateOrder::class)
            ->fillForm([
                'warehouse_id' => $fixture['warehouse']->id,
                'marketplace_platform_id' => $fixture['platform']->id,
                'items' => [[
                    'product_id' => null,
                    'quantity' => 1,
                    'selling_price' => '250.00',
                ]],
            ]);

        $productField = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');

        $this->assertNotNull($productField);
        $this->assertArrayHasKey(
            $fixture['product']->id,
            $productField->getSearchResults('OrderVisibilityProbe'),
        );
    }

    public function test_platform_responsibility_does_not_match_blank_order_platform(): void
    {
        $fixture = $this->fixture();
        $this->grantBrandPlatformScope($fixture);

        $results = $this->search($fixture['staff'], $fixture['product'], $fixture['warehouse'], null);

        $this->assertArrayNotHasKey($fixture['product']->id, $results);
    }

    public function test_platform_responsibility_does_not_match_different_order_platform(): void
    {
        $fixture = $this->fixture();
        $this->grantBrandPlatformScope($fixture);
        $otherPlatform = MarketplacePlatform::factory()->create();

        $results = $this->search($fixture['staff'], $fixture['product'], $fixture['warehouse'], $otherPlatform);

        $this->assertArrayNotHasKey($fixture['product']->id, $results);
    }

    public function test_warehouse_selection_without_matching_responsibility_does_not_show_product(): void
    {
        $fixture = $this->fixture();

        $results = $this->search($fixture['staff'], $fixture['product'], $fixture['warehouse'], $fixture['platform']);

        $this->assertArrayNotHasKey($fixture['product']->id, $results);
    }

    public function test_warehouse_scoped_responsibility_matches_only_the_selected_warehouse(): void
    {
        $fixture = $this->fixture();
        $otherWarehouse = Warehouse::factory()->create(['status' => true]);
        ProductInventory::factory()->create([
            'product_id' => $fixture['product']->id,
            'warehouse_id' => $otherWarehouse->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]);
        $this->grantWarehouseScope($fixture, $fixture['warehouse']);

        $this->assertArrayHasKey(
            $fixture['product']->id,
            $this->search($fixture['staff'], $fixture['product'], $fixture['warehouse'], null),
        );
        $this->assertArrayNotHasKey(
            $fixture['product']->id,
            $this->search($fixture['staff'], $fixture['product'], $otherWarehouse, null),
        );
    }

    public function test_search_uses_physical_sellable_quantity_not_gross_available_quantity(): void
    {
        $fixture = $this->fixture(available: 3, reserved: 3);
        $this->grantBrandPlatformScope($fixture);

        $this->assertArrayNotHasKey(
            $fixture['product']->id,
            $this->search($fixture['staff'], $fixture['product'], $fixture['warehouse'], $fixture['platform']),
        );

        $fixture['inventory']->update(['reserved_quantity' => 2]);

        $this->assertArrayHasKey(
            $fixture['product']->id,
            $this->search($fixture['staff'], $fixture['product'], $fixture['warehouse'], $fixture['platform']),
        );
    }

    public function test_owner_and_admin_use_same_search_path_without_employee_scope(): void
    {
        $fixture = $this->fixture();
        $admin = $this->user(EmployeeRole::Admin);

        foreach ([$fixture['owner'], $admin] as $user) {
            $results = $this->search($user, $fixture['product'], $fixture['warehouse'], $fixture['platform']);
            $this->assertArrayHasKey($fixture['product']->id, $results);
        }
    }

    public function test_stocked_authorized_product_after_first_150_zero_stock_candidates_is_returned(): void
    {
        config(['product_matching.candidate_limit' => 150]);

        $fixture = $this->fixture(createInventory: false);
        $this->grantBrandPlatformScope($fixture);

        for ($index = 1; $index <= 151; $index++) {
            $product = Product::factory()->create([
                'sku' => 'CANDIDATE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'name' => 'OrderVisibilityProbe Laptop',
                'brand' => $fixture['brand']->name,
                'brand_id' => $fixture['brand']->id,
            ]);

            if ($index === 151) {
                ProductInventory::factory()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => $fixture['warehouse']->id,
                    'available_quantity' => 1,
                    'reserved_quantity' => 0,
                ]);
            }
        }

        $target = Product::query()->where('sku', 'CANDIDATE-151')->firstOrFail();
        $matchingIds = Product::query()->where('sku', 'like', 'CANDIDATE-%')->orderBy('id')->pluck('id');
        $this->assertCount(151, $matchingIds);
        $this->assertSame($target->id, $matchingIds[150]);
        $this->assertTrue(app(OrderResponsibilityScopeService::class)->canAccessProduct(
            $fixture['staff'],
            $target->id,
            $fixture['platform']->id,
            $fixture['warehouse']->id,
        ));
        $this->assertSame(Product::query()->whereKey($target->id)->value('brand_id'), $fixture['brand']->id);
        $this->assertSame(0, ProductInventory::query()->where('warehouse_id', $fixture['warehouse']->id)
            ->whereIn('product_id', $matchingIds->take(150))->count());
        $results = app(ProductSearchOptions::class)->search(
            'OrderVisibilityProbe',
            ProductMatchContext::Order,
            $fixture['staff'],
            $fixture['warehouse']->id,
            $fixture['platform']->id,
        );

        $this->assertDatabaseHas('product_inventories', [
            'product_id' => $target->id,
            'warehouse_id' => $fixture['warehouse']->id,
            'available_quantity' => 1,
            'reserved_quantity' => 0,
        ]);
        $this->assertArrayHasKey($target->id, $results);
    }

    public function test_candidate_cap_still_limits_sellable_products(): void
    {
        config(['product_matching.candidate_limit' => 150]);

        $fixture = $this->fixture(createInventory: false);
        $this->grantBrandPlatformScope($fixture);

        for ($index = 1; $index <= 151; $index++) {
            $product = Product::factory()->create([
                'sku' => 'SELLABLE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'name' => 'OrderVisibilityProbe Laptop',
                'brand' => $fixture['brand']->name,
                'brand_id' => $fixture['brand']->id,
            ]);
            ProductInventory::factory()->create([
                'product_id' => $product->id,
                'warehouse_id' => $fixture['warehouse']->id,
                'available_quantity' => 2,
                'reserved_quantity' => 0,
            ]);
        }

        $results = app(ProductSearchOptions::class)->search(
            'OrderVisibilityProbe',
            ProductMatchContext::Order,
            $fixture['staff'],
            $fixture['warehouse']->id,
            $fixture['platform']->id,
        );

        $this->assertCount(12, $results);
        $this->assertLessThanOrEqual(150, count($results));
    }

    public function test_stock_in_other_warehouses_does_not_consume_selected_warehouse_candidate_limit(): void
    {
        config(['product_matching.candidate_limit' => 150]);

        $fixture = $this->fixture(createInventory: false);
        $this->grantBrandPlatformScope($fixture);
        $otherWarehouse = Warehouse::factory()->create(['status' => true]);

        for ($index = 1; $index <= 151; $index++) {
            $product = Product::factory()->create([
                'sku' => 'WAREHOUSE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'name' => 'OrderVisibilityProbe Laptop',
                'brand' => $fixture['brand']->name,
                'brand_id' => $fixture['brand']->id,
            ]);
            ProductInventory::factory()->create([
                'product_id' => $product->id,
                'warehouse_id' => $index === 151 ? $fixture['warehouse']->id : $otherWarehouse->id,
                'available_quantity' => 2,
                'reserved_quantity' => 0,
            ]);
        }

        $target = Product::query()->where('sku', 'WAREHOUSE-151')->firstOrFail();
        $results = app(ProductSearchOptions::class)->search(
            'OrderVisibilityProbe',
            ProductMatchContext::Order,
            $fixture['staff'],
            $fixture['warehouse']->id,
            $fixture['platform']->id,
        );

        $this->assertArrayHasKey($target->id, $results);
        $this->assertCount(1, $results);
    }

    public function test_non_order_contexts_keep_their_existing_stock_search_behavior(): void
    {
        $fixture = $this->fixture(createInventory: false);
        $search = app(ProductSearchOptions::class);

        foreach ([ProductMatchContext::Purchase, ProductMatchContext::Quotation] as $context) {
            $results = $search->search(
                'OrderVisibilityProbe',
                $context,
                $fixture['owner'],
                $fixture['warehouse']->id,
            );

            $this->assertArrayHasKey($fixture['product']->id, $results);
        }

        foreach ([ProductMatchContext::Order, ProductMatchContext::WebSales] as $context) {
            $results = $search->search(
                'OrderVisibilityProbe',
                $context,
                $fixture['owner'],
                $fixture['warehouse']->id,
            );

            $this->assertArrayNotHasKey($fixture['product']->id, $results);
        }
    }

    private function fixture(int $available = 5, int $reserved = 0, bool $createInventory = true): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create(['name' => 'Diagnostic Brand', 'normalized_name' => 'diagnostic brand']);
        $platform = MarketplacePlatform::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $product = Product::factory()->create([
            'name' => 'OrderVisibilityProbe Laptop',
            'brand' => $brand->name,
            'brand_id' => $brand->id,
        ]);
        $inventory = $createInventory ? ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => $available,
            'reserved_quantity' => $reserved,
        ]) : null;

        return compact('owner', 'staff', 'brand', 'platform', 'warehouse', 'product', 'inventory');
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function grantBrandPlatformScope(array $fixture): void
    {
        $now = now();
        $assignmentId = DB::table('responsibility_assignments')->insertGetId([
            'reference' => 'DIAG-'.Str::upper(Str::random(12)),
            'employee_id' => $fixture['staff']->employee->id,
            'team_id_at_assignment' => null,
            'team_name_at_assignment' => null,
            'assignment_mode' => 'scope',
            'status' => 'active',
            'active_fingerprint' => hash('sha256', (string) Str::uuid()),
            'effective_at' => $now->copy()->subMinute(),
            'ended_at' => null,
            'assigned_by_user_id' => $fixture['owner']->id,
            'ended_by_user_id' => null,
            'predecessor_assignment_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Disposable selector diagnostic',
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            'responsibility_assignment_brands' => ['product_brand_id' => $fixture['brand']->id],
            'responsibility_assignment_platforms' => ['marketplace_platform_id' => $fixture['platform']->id],
        ] as $table => $scope) {
            DB::table($table)->insert(['assignment_id' => $assignmentId, ...$scope, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function grantWarehouseScope(array $fixture, Warehouse $warehouse): void
    {
        $now = now();
        $assignmentId = DB::table('responsibility_assignments')->insertGetId([
            'reference' => 'DIAG-'.Str::upper(Str::random(12)),
            'employee_id' => $fixture['staff']->employee->id,
            'team_id_at_assignment' => null,
            'team_name_at_assignment' => null,
            'assignment_mode' => 'scope',
            'status' => 'active',
            'active_fingerprint' => hash('sha256', (string) Str::uuid()),
            'effective_at' => $now->copy()->subMinute(),
            'ended_at' => null,
            'assigned_by_user_id' => $fixture['owner']->id,
            'ended_by_user_id' => null,
            'predecessor_assignment_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Disposable warehouse selector diagnostic',
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('responsibility_assignment_warehouses')->insert([
            'assignment_id' => $assignmentId,
            'warehouse_id' => $warehouse->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function search(User $user, Product $product, Warehouse $warehouse, ?MarketplacePlatform $platform): array
    {
        return app(ProductSearchOptions::class)->search(
            'OrderVisibilityProbe',
            ProductMatchContext::Order,
            $user,
            $warehouse->id,
            $platform?->id,
        );
    }
}
