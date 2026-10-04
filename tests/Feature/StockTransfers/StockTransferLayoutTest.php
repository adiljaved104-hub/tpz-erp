<?php

namespace Tests\Feature\StockTransfers;

use App\Enums\EmployeeRole;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockTransferLayoutTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_source_changes_clear_stale_destination_and_products_and_keep_full_titles(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = Warehouse::factory()->create();
        $title = 'Full unshortened detailed laptop title including processor memory display storage and specifications';
        $product = Product::factory()->create(['name' => $title]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $source->id, 'available_quantity' => 5]);
        $page = Livewire::actingAs($owner)->test(CreateStockTransfer::class)
            ->fillForm(['source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]]])
            ->assertSee($title)
            ->assertFormFieldExists('destination_warehouse_id', fn (Select $field): bool => ! array_key_exists($source->id, $field->getOptions()))
            ->set('data.source_warehouse_id', $destination->id)
            ->assertSet('data.destination_warehouse_id', null);
        $this->assertNull(collect($page->get('data.items'))->first()['product_id']);
        $page->assertSee('Select From location and Product.');
    }

    public function test_same_location_is_still_rejected_even_when_form_is_tampered(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        Livewire::actingAs($owner)->test(CreateStockTransfer::class)
            ->fillForm(['source_warehouse_id' => $source->id, 'destination_warehouse_id' => $source->id])
            ->call('create')->assertHasFormErrors(['destination_warehouse_id']);
        $this->assertDatabaseCount('stock_transfers', 0);
    }
}
