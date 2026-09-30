<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\EmployeeRole;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequestGrid;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ProductMarketplaceListing;
use App\Models\ResponsibilityAssignment;
use App\Services\Inventory\InventoryAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockRequestGridTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_platform_employee_sees_holder_stock_and_submits_multiple_rows_without_transferring_inventory(): void
    {
        $f = $this->responsibilityFoundation(5);
        $staff = $f['employee']->user;
        $assignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $this->assertInstanceOf(ResponsibilityAssignment::class, $assignment);
        $secondProduct = Product::factory()->create();
        $second = ProductInventory::factory()->create([
            'product_id' => $secondProduct->id, 'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 4,
        ]);
        foreach ([$f['product'], $secondProduct] as $product) {
            ProductMarketplaceListing::query()->create([
                'product_id' => $product->id, 'marketplace_platform_id' => $f['platform']->id,
                'listing_title' => $product->name,
            ]);
        }
        $unrelated = ProductInventory::factory()->create([
            'product_id' => Product::factory(['brand_id' => ProductBrand::factory()]), 'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 2,
        ]);
        $holder = Employee::factory()->create(['status' => true, 'name' => 'Essa Stock Holder']);
        $allocation = app(InventoryAllocationService::class);
        foreach ([$f['inventory'], $second, $unrelated] as $inventory) {
            $allocation->ensureShadowCoverage($inventory, $f['owner']);
        }
        $holderAccount = $allocation->employeeAccount($holder->id);
        $allocation->reconcile($f['inventory'], $holderAccount, 3, $f['owner'], 'Holder setup');
        $allocation->reconcile($second, $holderAccount, 2, $f['owner'], 'Holder setup');

        $this->actingAs($staff);
        $component = Livewire::test(CreateStockRequestGrid::class)
            ->assertSee($f['product']->sku)
            ->assertSee($secondProduct->sku)
            ->assertSee('Essa Stock Holder')
            ->assertDontSee($unrelated->product->sku);
        $component->set("quantities.{$f['inventory']->id}", 2)
            ->set("quantities.{$second->id}", 1)
            ->set('reason', 'Needed for platform operations')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('stock_requests', 1);
        $this->assertDatabaseCount('stock_request_items', 2);
        $this->assertDatabaseHas('stock_request_source_lines', ['inventory_allocation_account_id' => $holderAccount->id, 'proposed_quantity' => 2]);
        $this->assertSame(5, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(4, $second->refresh()->available_quantity);
        $this->assertSame(3, $holderAccount->balances()->where('product_inventory_id', $f['inventory']->id)->value('allocated_quantity'));
    }

    public function test_unrelated_inventory_and_excess_quantity_cannot_be_submitted(): void
    {
        $f = $this->responsibilityFoundation(2);
        $staff = $f['employee']->user;
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $unrelated = ProductInventory::factory()->create([
            'product_id' => Product::factory(['brand_id' => ProductBrand::factory()]), 'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 2,
        ]);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocation->ensureShadowCoverage($unrelated, $f['owner']);

        $this->actingAs($staff);
        Livewire::test(CreateStockRequestGrid::class)
            ->set("quantities.{$unrelated->id}", 1)
            ->set('reason', 'Unrelated request attempt')
            ->call('submit')
            ->assertForbidden();
        $this->assertDatabaseCount('stock_requests', 0);

        Livewire::test(CreateStockRequestGrid::class)
            ->set("quantities.{$f['inventory']->id}", 3)
            ->set('reason', 'Need more stock now')
            ->call('submit')
            ->assertHasErrors();
        $this->assertDatabaseCount('stock_requests', 0);
    }

    public function test_actor_without_request_permission_is_denied(): void
    {
        $user = $this->responsibilityUser(EmployeeRole::Staff);
        $user->employee->forceFill(['status' => false])->save();
        $this->actingAs($user);
        Livewire::test(CreateStockRequestGrid::class)->assertForbidden();
    }
}
