<?php

namespace Tests\Feature\Navigation;

use App\Enums\EmployeeRole;
use App\Filament\Pages\InventoryWorkspace;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryWorkspaceStockOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_actual_stock_ownership_balance_and_filtered_results_inline(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create();
        $user = $owner->user;
        $product = Product::factory()->create(['sku' => 'TPZ-OWNER-STOCK-001', 'name' => 'Workspace ownership test laptop']);
        $warehouse = Warehouse::factory()->create();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
        ]);
        app(InventoryAllocationService::class)->reconcile(
            $inventory,
            app(InventoryAllocationService::class)->employeeAccount($owner->id),
            5,
            $user,
            'Workspace owner balance test',
        );

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($user, true);

        $component = Livewire::test(InventoryWorkspace::class);
        $index = collect($component->viewData('extraModules'))->search(
            fn (array $item): bool => $item['label'] === 'Stock Ownership',
        );

        $this->assertNotFalse($index);

        $component->call('selectExtraModule', (int) $index)
            ->assertSee($product->sku)
            ->assertSee($product->name)
            ->assertSee('Total Held')
            ->assertSee('Stock Holder')
            ->set('ownershipSearch', 'SKU-NO-MATCH-999')
            ->assertDontSee($product->sku)
            ->assertSee('No stock ownership records match these filters.')
            ->call('clearOwnershipFilters')
            ->assertSee($product->sku);

        $this->assertSame(10, $inventory->refresh()->available_quantity);
    }

    public function test_invalid_stock_ownership_module_filter_action_is_forbidden(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(InventoryWorkspace::class)
            ->call('clearOwnershipFilters')
            ->assertForbidden();
    }
}
