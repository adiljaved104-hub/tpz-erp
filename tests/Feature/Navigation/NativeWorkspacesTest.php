<?php

namespace Tests\Feature\Navigation;

use App\Enums\EmployeeRole;
use App\Filament\Pages\AdministrationWorkspace;
use App\Filament\Pages\FinanceWorkspace;
use App\Filament\Pages\InventoryWorkspace;
use App\Filament\Pages\PurchasingWorkspace;
use App\Filament\Pages\ReturnsQcWorkspace;
use App\Filament\Pages\SalesWorkspace;
use App\Filament\Pages\TeamHrWorkspace;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NativeWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_pages_are_unavailable_before_owner_opt_in_and_to_staff(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $staff = Employee::factory()->role(EmployeeRole::Staff)->create()->user;

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertFalse(SalesWorkspace::canAccess());
        $this->assertFalse(InventoryWorkspace::canAccess());

        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);
        $this->assertTrue(SalesWorkspace::canAccess());
        $this->assertTrue(InventoryWorkspace::canAccess());

        $this->actingAs($staff);
        $this->assertFalse(SalesWorkspace::canAccess());
        $this->assertFalse(InventoryWorkspace::canAccess());
    }

    public function test_owner_opt_in_controls_all_operational_workspace_routes(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $staff = Employee::factory()->role(EmployeeRole::Staff)->create()->user;
        $pages = [
            PurchasingWorkspace::class,
            ReturnsQcWorkspace::class,
            FinanceWorkspace::class,
            TeamHrWorkspace::class,
            AdministrationWorkspace::class,
        ];

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ($pages as $page) {
            $this->assertFalse($page::canAccess());
        }

        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);
        foreach ($pages as $page) {
            $this->assertTrue($page::canAccess(), $page.' should be visible only after Owner opt-in');
            Livewire::test($page)->assertSee('Workspace');
        }

        $this->actingAs($staff);
        foreach ($pages as $page) {
            $this->assertFalse($page::canAccess());
        }
    }

    public function test_purchasing_workspace_counts_actual_scoped_purchase_and_links_recent_order(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Purchase::factory()->create([
            'reference' => 'PO-VISUAL-ACTIVITY-001',
            'created_by_user_id' => $owner->id,
        ]);

        Livewire::test(PurchasingWorkspace::class)
            ->assertSee('Operational overview')
            ->assertSee('Recent authorized records')
            ->assertSee('PO-VISUAL-ACTIVITY-001');
    }

    public function test_inventory_workspace_search_looks_beyond_first_page_within_owner_scope(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        $olderProduct = Product::factory()->create(['sku' => 'TPZ-OLDEST-UNIQUE-SEARCH']);
        ProductInventory::factory()->create([
            'product_id' => $olderProduct->id,
            'available_quantity' => 8,
            'reserved_quantity' => 0,
            'updated_at' => now()->subDays(5),
        ]);

        foreach (range(1, 17) as $index) {
            $product = Product::factory()->create(['sku' => sprintf('TPZ-NEWER-%03d', $index)]);
            ProductInventory::factory()->create(['product_id' => $product->id, 'updated_at' => now()]);
        }

        Livewire::test(InventoryWorkspace::class)
            ->assertDontSee('TPZ-OLDEST-UNIQUE-SEARCH')
            ->set('search', 'TPZ-OLDEST-UNIQUE-SEARCH')
            ->assertSee('TPZ-OLDEST-UNIQUE-SEARCH');
    }

    public function test_owner_inventory_workspace_shows_permission_scoped_recent_movement(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        $inventory = ProductInventory::factory()->create();
        StockMovement::factory()->create([
            'reference' => 'SM-REFERENCE-DESIGN-001',
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'actor_user_id' => $owner->id,
        ]);

        Livewire::test(InventoryWorkspace::class)
            ->assertSee('Recent inventory activity')
            ->assertSee('View movement history');
    }

    public function test_owner_sees_existing_product_and_original_module_link_without_modifying_records(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        $product = Product::factory()->create(['sku' => 'TPZ-NATIVE-LIVE-123', 'name' => 'Original catalog stock']);
        ProductInventory::factory()->create(['product_id' => $product->id, 'available_quantity' => 7]);

        Livewire::test(SalesWorkspace::class)->assertSee('Sales Workspace');
        Livewire::test(InventoryWorkspace::class)
            ->assertSee('Inventory & Warehouse')
            ->assertSee('Products')
            ->assertSee('TPZ-NATIVE-LIVE-123')
            ->assertSee('Original catalog stock');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('products', 1);
    }
}
