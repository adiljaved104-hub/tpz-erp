<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Pages\Inventory\MyInventory;
use App\Models\Product;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
        $this->assertLessThanOrEqual(15, $queries, 'My Inventory must batch-load Product visibility and stock context.');
    }
}
