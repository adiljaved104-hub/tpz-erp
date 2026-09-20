<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Pages\Inventory\MyInventory;
use App\Models\EmployeePermissionOverride;
use App\Models\InventoryReservation;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MyInventoryTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_empty_page_renders_only_the_clean_empty_state_without_table_headers(): void
    {
        $foundation = $this->responsibilityFoundation();

        Livewire::actingAs($foundation['employee']->user)->test(MyInventory::class)
            ->assertOk()
            ->assertSee('No active Product responsibilities.')
            ->assertDontSee('Latest Purchase Cost');
    }

    public function test_populated_page_renders_the_inventory_table_without_blade_errors(): void
    {
        $foundation = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($foundation), $foundation['owner']);

        Livewire::actingAs($foundation['employee']->user)->test(MyInventory::class)
            ->assertOk()
            ->assertSee($foundation['product']->sku)
            ->assertSee('Sellable')
            ->assertDontSee('No active Product responsibilities.');
    }

    public function test_desktop_inventory_details_render_in_a_full_width_responsive_row(): void
    {
        $foundation = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($foundation), $foundation['owner']);

        Livewire::actingAs($foundation['employee']->user)->test(MyInventory::class)
            ->assertOk()
            ->assertSeeHtml('data-inventory-details-toggle')
            ->assertSeeHtml('data-inventory-details-row')
            ->assertSeeHtml('colspan="11"')
            ->assertSeeHtml('sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6')
            ->assertSee('Original Allocation')
            ->assertSee('Outstanding Allocation')
            ->assertSee('Remaining Assignable')
            ->assertSee('Capacity');
    }

    public function test_widgets_are_employee_scoped_and_ignore_unauthorized_inventory(): void
    {
        $f = $this->responsibilityFoundation(2, 0);
        $this->assignProduct($f, $f['product']);
        $unrelated = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $unrelated->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertViewHas('summary', fn (array $summary): bool => $summary === ['products' => 1, 'usable' => 2, 'low' => 0, 'out' => 0]);
    }

    public function test_owner_approved_stock_status_thresholds_are_used(): void
    {
        $f = $this->responsibilityFoundation(0, 0);
        $one = Product::factory()->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name]);
        $two = Product::factory()->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name]);
        ProductInventory::factory()->create(['product_id' => $one->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 1, 'reserved_quantity' => 0]);
        ProductInventory::factory()->create(['product_id' => $two->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 2, 'reserved_quantity' => 0]);
        $this->assignProduct($f, $f['product']);
        $this->assignProduct($f, $one);
        $this->assignProduct($f, $two);

        $rows = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->keyBy('product_id');
        $this->assertSame('out_of_stock', $rows[$f['product']->id]->stock_status);
        $this->assertSame('low_stock', $rows[$one->id]->stock_status);
        $this->assertSame('in_stock', $rows[$two->id]->stock_status);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertViewHas('summary', fn (array $summary): bool => $summary['low'] === 1 && $summary['out'] === 1);
    }

    public function test_quantity_responsibility_uses_authoritative_remaining_allocation(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        $assignment = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 1]),
            $f['owner'],
        );
        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertTrue($row->is_quantity_limited);
        $this->assertSame(1, $row->employee_usable);
        $this->assertSame('low_stock', $row->stock_status);

        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $f['inventory']->id,
            'product_id' => $f['product']->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'quantity' => 1,
        ]);
        DB::table('responsibility_inventory_consumptions')->insert([
            'responsibility_assignment_id' => $assignment->id,
            'inventory_reservation_id' => $reservation->id,
            'order_fulfillment_item_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exhausted = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertSame(0, $exhausted->employee_usable);
        $this->assertSame('out_of_stock', $exhausted->stock_status);
    }

    #[DataProvider('quantityUsableStockCases')]
    public function test_quantity_usable_stock_is_capped_by_both_physical_sellable_and_remaining_allocation(int $physical, int $allocation, int $expected): void
    {
        $f = $this->responsibilityFoundation(max($physical, $allocation), 0);
        app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => $allocation]),
            $f['owner'],
        );
        $f['inventory']->forceFill(['available_quantity' => $physical])->save();

        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertSame($physical, $row->sellable);
        $this->assertSame($allocation, $row->remaining_allocation);
        $this->assertSame($expected, $row->employee_usable);
    }

    public static function quantityUsableStockCases(): array
    {
        return [
            'allocation below physical' => [10, 5, 5],
            'physical below allocation' => [3, 5, 3],
            'larger physical stock' => [15, 10, 10],
        ];
    }

    public function test_shared_responsibility_uses_physical_sellable_stock(): void
    {
        $f = $this->responsibilityFoundation(10, 3);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);

        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();

        $this->assertFalse($row->is_quantity_limited);
        $this->assertSame(7, $row->sellable);
        $this->assertSame(7, $row->employee_usable);
    }

    public function test_exact_quantity_assignment_caps_a_matching_broader_brand_scope(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 1]),
            $f['owner'],
        );

        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertSame(10, $row->sellable);
        $this->assertTrue($row->is_quantity_limited);
        $this->assertSame(1, $row->employee_usable);
        $this->assertSame('low_stock', $row->stock_status);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertViewHas('summary', fn (array $summary): bool => $summary['usable'] === 1 && $summary['low'] === 1 && $summary['out'] === 0)
            ->assertSee('1 of 1')
            ->assertSee('Low Stock')
            ->assertSeeHtml('hover:border-amber-400')
            ->assertSeeHtml('hover:border-red-400')
            ->assertSeeHtml('class="flex size-8 shrink-0 items-center justify-center rounded-lg transition duration-150');
    }

    public function test_exhausted_exact_quantity_assignment_caps_a_matching_broader_brand_scope_at_zero(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $assignment = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 1]),
            $f['owner'],
        );
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $f['inventory']->id,
            'product_id' => $f['product']->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'quantity' => 1,
        ]);
        DB::table('responsibility_inventory_consumptions')->insert([
            'responsibility_assignment_id' => $assignment->id,
            'inventory_reservation_id' => $reservation->id,
            'order_fulfillment_item_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertSame(10, $row->sellable);
        $this->assertSame(0, $row->employee_usable);
        $this->assertSame('out_of_stock', $row->stock_status);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertViewHas('summary', fn (array $summary): bool => $summary['usable'] === 0 && $summary['low'] === 0 && $summary['out'] === 1)
            ->assertSee('Allocation Exhausted');
    }

    public function test_search_supports_sku_product_name_and_model(): void
    {
        $f = $this->responsibilityFoundation();
        $f['product']->forceFill(['name' => 'EliteBook Daily Laptop', 'model' => '840-G11'])->save();
        $other = Product::factory()->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name, 'name' => 'Other Device']);
        ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id]);
        $this->assignProduct($f, $f['product']);
        $this->assignProduct($f, $other);

        $component = Livewire::actingAs($f['employee']->user)->test(MyInventory::class);
        $component->set('search', $f['product']->sku)->assertViewHas('inventoryRows', fn ($rows): bool => $rows->pluck('product_id')->all() === [$f['product']->id]);
        $component->set('search', 'daily laptop')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->pluck('product_id')->all() === [$f['product']->id]);
        $component->set('search', '840-g11')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->pluck('product_id')->all() === [$f['product']->id]);
    }

    public function test_brand_category_platform_and_warehouse_filters_stay_inside_scope(): void
    {
        $f = $this->responsibilityFoundation();
        $otherBrand = ProductBrand::factory()->create(['name' => 'Dell', 'normalized_name' => 'dell']);
        $other = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => 'Dell']);
        ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id]);
        $otherPlatform = MarketplacePlatform::factory()->create(['name' => 'Noon UAE', 'normalized_name' => 'noon uae', 'code' => 'noon_uae']);
        $this->assignProduct($f, $f['product'], $f['platform']->id);
        $this->assignProduct($f, $other, $otherPlatform->id);

        $component = Livewire::actingAs($f['employee']->user)->test(MyInventory::class);
        $component->set('brand', 'HP')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->pluck('product_id')->all() === [$f['product']->id]);
        $component->set('brand', '')->set('category', $f['product']->categoryRelation->name)->assertViewHas('inventoryRows', fn ($rows): bool => $rows->contains('product_id', $f['product']->id));
        $component->set('category', '')->set('platform', 'Noon UAE')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->pluck('product_id')->all() === [$other->id]);
        $component->set('platform', '')->set('warehouse', $f['inventory']->warehouse->name)->assertViewHas('inventoryRows', fn ($rows): bool => $rows->pluck('product_id')->sort()->values()->all() === collect([$f['product']->id, $other->id])->sort()->values()->all());
    }

    public function test_stock_and_allocation_filters_identify_daily_exceptions(): void
    {
        $f = $this->responsibilityFoundation(1, 0);
        $this->assignProduct($f, $f['product']);
        $component = Livewire::actingAs($f['employee']->user)->test(MyInventory::class);

        $component->call('applyStockStatus', 'low_stock')->assertSet('stockStatus', 'low_stock')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->count() === 1);
        $component->set('stockStatus', 'out_of_stock')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->isEmpty());
        $component->set('stockStatus', '')->set('allocation', 'shared')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->count() === 1);
        $component->set('allocation', 'quantity')->assertViewHas('inventoryRows', fn ($rows): bool => $rows->isEmpty());
    }

    public function test_multiple_platform_responsibilities_render_as_badges_without_duplicate_inventory_rows(): void
    {
        $f = $this->responsibilityFoundation();
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon UAE', 'normalized_name' => 'noon uae', 'code' => 'noon_uae']);
        $this->assignProduct($f, $f['product'], $f['platform']->id);
        $this->assignProduct($f, $f['product'], $noon->id);

        $rows = app(ResponsibilityReadService::class)->myInventory($f['employee']->user);
        $this->assertCount(1, $rows);
        $this->assertEqualsCanonicalizing(['Amazon UAE', 'Noon UAE'], $rows->sole()->platforms);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertSee('Amazon UAE')
            ->assertSee('Noon UAE');
    }

    public function test_platform_only_responsibility_uses_the_existing_all_products_platform_scope(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null,
            'platformId' => $f['platform']->id,
        ]), $f['owner']);

        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertSame($f['product']->id, $row->product_id);
        $this->assertSame(['Amazon UAE'], $row->platforms);
        $this->assertSame(['Platform: Amazon UAE'], $row->visibility_reasons);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertSee('Platform')
            ->assertSee('Amazon UAE');
    }

    public function test_product_without_inventory_balance_renders_safe_no_balance_state(): void
    {
        $f = $this->responsibilityFoundation();
        $product = Product::factory()->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name]);
        $this->assignProduct($f, $product);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertSee($product->sku)
            ->assertSee('No balance')
            ->assertSee('Out of Stock');
    }

    public function test_cost_projection_follows_existing_purchase_cost_history_permission(): void
    {
        $f = $this->responsibilityFoundation();
        $this->assignProduct($f, $f['product']);
        $staffRow = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();
        $this->assertObjectNotHasProperty('latest_purchase_cost', $staffRow);

        $ownerFoundation = $f;
        $ownerFoundation['employee'] = $f['owner']->employee;
        $this->assignProduct($ownerFoundation, $f['product']);
        $ownerRow = app(ResponsibilityReadService::class)->myInventory($f['owner'])->sole();
        $this->assertObjectHasProperty('latest_purchase_cost', $ownerRow);
    }

    public function test_explicit_view_own_denial_keeps_page_inaccessible(): void
    {
        $f = $this->responsibilityFoundation();
        EmployeePermissionOverride::query()->create([
            'employee_id' => $f['employee']->id,
            'permission_key' => 'responsibility.view_own',
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $f['owner']->id,
            'reason' => 'Focused access test',
        ]);

        $this->actingAs($f['employee']->user);
        $this->assertFalse(MyInventory::canAccess());
        Livewire::test(MyInventory::class)->assertForbidden();
    }

    public function test_overlapping_assignments_consolidate_products_and_omit_owner_only_financial_fields(): void
    {
        $f = $this->responsibilityFoundation(20, 5);
        $action = app(CreateResponsibilityAssignment::class);
        $action->handle($this->assignmentData($f), $f['owner']);
        $action->handle($this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id, 'platformId' => $f['platform']->id]), $f['owner']);
        $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 4]), $f['owner']);
        $rows = app(ResponsibilityReadService::class)->myInventory($f['employee']->user);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(15, $row->sellable);
        $this->assertSame(4, $row->assigned_quantity);
        $this->assertCount(3, $row->visibility_reasons);
        $this->assertObjectNotHasProperty('average_cost', $row);
        $this->assertObjectNotHasProperty('cost_price', $row);
        $this->assertObjectNotHasProperty('inventory_value', $row);
    }

    public function test_reservation_reduction_surfaces_over_assignment_without_mutation(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 10]), $f['owner']);
        $f['inventory']->forceFill(['reserved_quantity' => 3])->save();
        $row = app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole();

        $this->assertSame('Over Assigned', $row->capacity_status);
        $this->assertSame(-3, $row->remaining_assignable);
        $this->assertSame(10, $f['inventory']->refresh()->available_quantity);
    }

    public function test_brand_visibility_is_batch_loaded_without_per_product_queries(): void
    {
        $f = $this->responsibilityFoundation();
        Product::factory()->count(12)->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name]);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(ResponsibilityReadService::class)->myInventory($f['employee']->user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(13, $rows);
        $this->assertLessThanOrEqual(16, $queries, 'My Inventory must batch-load Product visibility, Condition scope, and stock context.');
    }

    public function test_employee_inventory_is_paginated_with_sensible_per_page_controls(): void
    {
        $f = $this->responsibilityFoundation();
        Product::factory()->count(30)->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name])
            ->each(fn (Product $product) => ProductInventory::factory()->create([
                'product_id' => $product->id,
                'warehouse_id' => $f['inventory']->warehouse_id,
            ]));
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertViewHas('inventoryRows', fn ($rows): bool => $rows->total() === 31 && $rows->count() === 25 && $rows->perPage() === 25)
            ->set('perPage', 10)
            ->assertViewHas('inventoryRows', fn ($rows): bool => $rows->total() === 31 && $rows->count() === 10 && $rows->perPage() === 10)
            ->assertSee('Per page');
    }

    public function test_condition_is_visible_filterable_and_long_product_titles_are_not_clamped(): void
    {
        $f = $this->responsibilityFoundation();
        $longTitle = 'HP EliteBook Renewed Business Laptop with a deliberately complete descriptive product title';
        $f['product']->forceFill(['condition' => ProductCondition::Renewed, 'name' => $longTitle])->save();
        $other = Product::factory()->create(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name, 'condition' => ProductCondition::New]);
        ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id]);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);

        Livewire::actingAs($f['employee']->user)->test(MyInventory::class)
            ->assertSee($longTitle)
            ->assertSee('Renewed')
            ->assertSee('Condition')
            ->set('condition', ProductCondition::Renewed->value)
            ->assertViewHas('inventoryRows', fn ($rows): bool => $rows->pluck('product_id')->all() === [$f['product']->id])
            ->assertDontSeeHtml('line-clamp-2 font-medium leading-5');
    }

    public function test_column_preferences_are_isolated_per_user_and_reset_to_defaults(): void
    {
        $first = $this->responsibilityFoundation();
        $second = $this->responsibilityUser(EmployeeRole::Staff);

        Livewire::actingAs($first['employee']->user)->test(MyInventory::class)
            ->assertSet('visibleColumns', fn (array $columns): bool => in_array('condition', $columns, true))
            ->call('toggleInventoryColumn', 'condition')
            ->assertSet('visibleColumns', fn (array $columns): bool => ! in_array('condition', $columns, true));

        Livewire::actingAs($second)->test(MyInventory::class)
            ->assertSet('visibleColumns', fn (array $columns): bool => in_array('condition', $columns, true));

        Livewire::actingAs($first['employee']->user)->test(MyInventory::class)
            ->call('resetInventoryColumns')
            ->assertSet('visibleColumns', fn (array $columns): bool => in_array('condition', $columns, true));
        $this->assertDatabaseMissing('user_ui_preferences', ['user_id' => $first['employee']->user_id, 'preference_key' => 'my_inventory.columns']);
    }

    private function assignProduct(array $foundation, Product $product, ?int $platformId = null): void
    {
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($foundation, overrides: [
            'brandId' => null,
            'productId' => $product->id,
            'platformId' => $platformId,
        ]), $foundation['owner']);
    }
}
