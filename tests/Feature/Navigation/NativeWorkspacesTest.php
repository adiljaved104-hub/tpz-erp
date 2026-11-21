<?php

namespace Tests\Feature\Navigation;

use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\EmployeeRole;
use App\Enums\StockRequestPurpose;
use App\Filament\Pages\AdministrationWorkspace;
use App\Filament\Pages\FinanceWorkspace;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Pages\InventoryWorkspace;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Filament\Pages\PurchasingWorkspace;
use App\Filament\Pages\ReturnsQcWorkspace;
use App\Filament\Pages\SalesWorkspace;
use App\Filament\Pages\TeamHrWorkspace;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\TaxInvoice;
use App\Services\CompanyProfileService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\StockRequestService;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_short_workspace_labels_stay_in_primary_navigation_instead_of_workplace_tools(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $navigation = app(NavigationPreferenceService::class);
        $navigation->setWorkspaceMode($owner, true);

        $groups = $navigation->apply($owner, $navigation->authorizedNavigation());
        $primary = collect($groups)
            ->first(fn ($group): bool => blank($group->getLabel()));

        $this->assertNotNull($primary);
        $labels = collect($primary->getItems())->map(fn ($item): string => $item->getLabel())->all();

        foreach (['Sales', 'Inventory', 'Purchase', 'Return & QC', 'Finance and Accounting'] as $workspace) {
            $this->assertContains($workspace, $labels);
        }

        $this->assertNotContains('Sales Orders', $labels);
        $this->assertNotContains('Tax Invoices', $labels);
    }

    public function test_tax_invoice_workspace_tab_opens_authorized_invoice_preview(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        $workspace = Livewire::test(SalesWorkspace::class);
        $modules = $workspace->viewData('extraModules');
        $taxInvoiceIndex = collect($modules)->search(fn (array $item): bool => $item['label'] === 'Tax Invoices');

        $this->assertNotFalse($taxInvoiceIndex, 'Tax Invoices must be available in Sales Workspace');

        $workspace->call('selectExtraModule', (int) $taxInvoiceIndex)
            ->assertSet('activeTab', 'extra')
            ->assertSet('activeExtraModule', (int) $taxInvoiceIndex)
            ->assertSee('Tax Invoices');
    }

    public function test_inventory_products_tab_search_is_scoped_and_filters_sku_and_name(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        $shown = Product::factory()->create(['sku' => 'TPZ-SEARCH-001', 'name' => 'Unique Alpha Device']);
        $hidden = Product::factory()->create(['sku' => 'TPZ-SEARCH-002', 'name' => 'Unique Beta Device']);

        Livewire::test(InventoryWorkspace::class)
            ->call('setTab', 'products')
            ->assertSee($shown->name)
            ->assertSee($hidden->name)
            ->set('productSearch', 'Alpha')
            ->assertSee($shown->name)
            ->assertDontSee($hidden->name)
            ->set('productSearch', 'TPZ-SEARCH-002')
            ->assertSee($hidden->name)
            ->assertDontSee($shown->name);
    }

    public function test_returns_workspace_create_modal_reuses_original_form_without_posting_stock(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(ReturnsQcWorkspace::class)
            ->assertActionVisible('createCustomerReturn')
            ->mountAction('createCustomerReturn')
            ->assertActionMounted('createCustomerReturn');

        $this->assertDatabaseCount('customer_returns', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_phase5_purchasing_products_and_quick_purchase_modal_reuse_existing_forms(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);
        Product::factory()->create(['name' => 'Phase Five Full Product Title']);
        Livewire::test(PurchasingWorkspace::class)
            ->assertActionVisible('quickPurchase')
            ->call('selectProducts')
            ->assertSee('Phase Five Full Product Title')
            ->set('productSearch', 'not-in-catalog')->assertDontSee('Phase Five Full Product Title')
            ->assertActionVisible('createPurchasingProduct')
            ->mountAction('quickPurchase')->assertActionMounted('quickPurchase');
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        Livewire::test(SalesWorkspace::class)->assertActionExists('createWorkspaceTaxInvoice')
            ->mountAction('createWorkspaceTaxInvoice')->assertActionMounted('createWorkspaceTaxInvoice');
        Livewire::test(SalesWorkspace::class)
            ->call('setTab', 'webSales')
            ->assertActionVisible('createWorkspaceWebSale')
            ->mountAction('createWorkspaceWebSale')
            ->assertActionMounted('createWorkspaceWebSale');
        $this->assertDatabaseCount('tax_invoices', 0);
    }

    public function test_phase5_product_modal_creates_and_refreshes_catalog_without_inventory_changes(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);
        $existing = Product::factory()->create();
        Livewire::test(PurchasingWorkspace::class)
            ->callAction('createPurchasingProduct', [
                'name' => 'Phase Five Unique Monitor 42 inch',
                'brand_id' => $existing->brand_id,
                'category_id' => $existing->category_id,
                'condition' => 'new', 'warranty' => 12,
                'selling_price' => '100.00',
            ])
            ->assertHasNoActionErrors()
            ->assertSet('activeModule', 'products')
            ->assertSee('Phase Five Unique Monitor 42 inch')
            ->assertSee('Products (2)');
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_phase5_staff_cannot_access_workspace_modals_or_embedded_purchase(): void
    {
        $this->actingAs(Employee::factory()->role(EmployeeRole::Staff)->create()->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PurchasingWorkspace::class)->assertForbidden();
        Livewire::test(SalesWorkspace::class)->assertForbidden();
        Livewire::test(QuickStockPurchase::class, ['embedded' => true])->assertForbidden();
    }

    public function test_phase5_invoice_modal_preserves_vat_idempotency_and_refreshes_records(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);
        app(CompanyProfileService::class)->save([
            'company_name_en' => 'Workspace Test Company', 'address_en' => 'Dubai, UAE',
            'trn' => '100000000000001', 'phone' => '+9714000000',
            'email' => 'accounts@example.test',
        ], $owner);
        $data = [
            'customer_name' => 'Modal test customer', 'customer_address' => 'Dubai', 'order_reference' => 'PHASE5-INVOICE',
            'invoice_date' => '2026-11-20', 'idempotency_key' => (string) Str::uuid(),
            'title_mode' => 'auto', 'terms_profile' => 'auto',
            'items' => [['description' => 'Monitor', 'quantity' => 2, 'unit_price_including_vat' => '105.00']],
        ];
        $workspace = Livewire::test(SalesWorkspace::class);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $workspace->mountAction('createWorkspaceTaxInvoice')
                ->set('mountedActions.0.data', $data)
                ->callMountedAction()->assertHasNoActionErrors();
        }
        $this->assertDatabaseCount('tax_invoices', 1);
        $invoice = TaxInvoice::query()->sole();
        $this->assertSame('210.00', $invoice->grand_total);
        $this->assertSame('10.00', $invoice->vat_amount);
        $workspace->assertDontSee('createSalesProduct');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_workspace_submits_and_lists_multi_product_stock_requests_without_moving_stock(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create(['name' => 'Workspace Requester'])->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);
        $inventories = collect(['First Workspace Product', 'Second Workspace Product', 'Third Workspace Product'])
            ->map(function (string $name) use ($owner): ProductInventory {
                $stock = ProductInventory::factory()->create([
                    'product_id' => Product::factory()->create(['name' => $name])->id,
                    'available_quantity' => 5,
                    'reserved_quantity' => 0,
                ]);
                app(InventoryAllocationService::class)->ensureShadowCoverage($stock, $owner);

                return $stock;
            });
        $lines = $inventories->map(fn (ProductInventory $stock): array => ['product_inventory_id' => $stock->id, 'quantity' => 1])->all();
        Livewire::test(InventoryWorkspace::class)
            ->assertActionVisible('createStockRequest')
            ->mountAction('createStockRequest')
            ->assertActionMounted('createStockRequest')
            ->fillForm(['purpose' => 'permanent_transfer', 'reason' => 'Need stock for workspace review'])
            ->set('mountedActions.0.data.items', ['first' => $lines[0], 'duplicate' => $lines[0]])
            ->callMountedAction()
            ->assertHasErrors(['mountedActions.0.data.items.first.product_inventory_id']);
        $this->assertDatabaseCount('stock_requests', 0);

        $authorized = Livewire::test(InventoryWorkspace::class)
            ->mountAction('createStockRequest')
            ->fillForm(['purpose' => 'permanent_transfer', 'reason' => 'Need stock for workspace review'])
            ->set('mountedActions.0.data.items', array_combine(['first', 'second', 'third'], $lines))
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet('activeTab', 'requests')
            ->assertSee('Workspace Requester')
            ->assertSee('Show 1 more products')
            ->assertSee('Third Workspace Product')
            ->assertSee('Total Requested Quantity');

        $request = StockRequest::query()->sole();
        $this->assertSame(3, (int) $request->items()->sum('quantity'));
        $retry = app(StockRequestService::class)->create(new CreateStockRequestData(
            StockRequestPurpose::PermanentTransfer, null,
            $inventories->map(fn (ProductInventory $stock): StockRequestItemData => new StockRequestItemData($stock->id, 1))->all(),
            'Need stock for workspace review', $authorized->instance()->stockRequestSubmissionKey,
        ), $owner);
        $this->assertSame($request->id, $retry->id);
        $this->assertDatabaseCount('stock_requests', 1);
        $this->assertSame([5, 5, 5], $inventories->map(fn (ProductInventory $stock): int => $stock->refresh()->available_quantity)->all());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_workspace_embeds_the_existing_adjustment_grid_and_checks_preview_access(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(InventoryWorkspace::class)->call('setTab', 'adjustments')
            ->assertSee('Prepare Stock Adjustments')->assertSee('Save All')->assertSee('Reset History Filters')
            ->assertDontSee('Open Stock Adjustments');

        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, false);
        Livewire::test(StockAdjustments::class, ['embedded' => true])->assertForbidden();
        Livewire::test(StockAdjustments::class)->assertSee('Prepare Stock Adjustments');
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
            ->assertSee('Recent Stock Updates')
            ->assertSee('View Inventory');
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

    public function test_inventory_workspace_keeps_overview_stock_visible_but_omits_it_on_other_tabs(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        $product = Product::factory()->create(['sku' => 'TPZ-PHASE2-STOCK-001']);
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'available_quantity' => 6,
        ]);

        Livewire::test(InventoryWorkspace::class)
            ->assertSee('Authorized Inventory')
            ->assertSee('TPZ-PHASE2-STOCK-001')
            ->call('setTab', 'requests')
            ->assertDontSee('Authorized Inventory')
            ->call('setTab', 'inventory')
            ->assertSee('Authorized Inventory')
            ->assertSee('TPZ-PHASE2-STOCK-001');
    }

    public function test_sales_quick_view_rejects_missing_records_and_invalid_module_types(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(SalesWorkspace::class)
            ->assertSet('quickViewOrderId', null)
            ->call('closeOrderQuickView')
            ->assertSet('quickViewOrderType', null);

        Livewire::test(SalesWorkspace::class)
            ->call('openOrderQuickView', 'orders', 99999999)
            ->assertNotFound();

        Livewire::test(SalesWorkspace::class)
            ->call('openOrderQuickView', 'unknown', 1)
            ->assertForbidden();
    }

    public function test_inventory_quick_view_loads_authorized_stock_and_can_close(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        $product = Product::factory()->create(['sku' => 'TPZ-INVENTORY-QUICK-001']);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'available_quantity' => 12,
            'reserved_quantity' => 3,
            'damaged_quantity' => 1,
        ]);

        Livewire::test(InventoryWorkspace::class)
            ->call('openInventoryQuickView', $inventory->id)
            ->assertSet('quickViewInventoryId', $inventory->id)
            ->assertSee('TPZ-INVENTORY-QUICK-001')
            ->assertSee('Recent permitted movements')
            ->call('closeInventoryQuickView')
            ->assertSet('quickViewInventoryId', null);
    }

    public function test_inventory_quick_view_rejects_unknown_inventory_id(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(InventoryWorkspace::class)
            ->call('openInventoryQuickView', 99999999)
            ->assertNotFound();
    }

    public function test_sales_workspace_requires_selected_order_and_confirmation_before_reserve(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(SalesWorkspace::class)
            ->assertSet('confirmQuickViewReserve', false)
            ->call('reserveQuickViewDraft')
            ->assertForbidden();
    }

    public function test_inventory_workspace_execution_requires_prepared_authorized_request(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(InventoryWorkspace::class)
            ->assertSet('pendingExecutionRequestId', null)
            ->call('executePreparedStockRequest')
            ->assertForbidden();

        Livewire::test(InventoryWorkspace::class)
            ->call('prepareStockRequestExecution', 99999999)
            ->assertNotFound();
    }

    public function test_workspace_source_approval_requires_an_authorized_request(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(InventoryWorkspace::class)
            ->assertSet('pendingSourceDecisionRequestId', null)
            ->call('submitSourceDecision')
            ->assertForbidden();

        Livewire::test(InventoryWorkspace::class)
            ->call('prepareSourceDecision', 99999999)
            ->assertNotFound();
    }

    public function test_owner_workspace_transfer_requires_an_authorized_request(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(InventoryWorkspace::class)
            ->assertSet('pendingOwnerTransferRequestId', null)
            ->call('submitOwnerApproveTransfer')
            ->assertForbidden();

        Livewire::test(InventoryWorkspace::class)
            ->call('prepareOwnerApproveTransfer', 99999999)
            ->assertNotFound();
    }

    public function test_web_sales_workspace_shipment_requires_selected_order_and_confirmation(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(SalesWorkspace::class)
            ->assertSet('pendingWebSaleOperation', null)
            ->call('submitQuickViewWebSale')
            ->assertForbidden();

        Livewire::test(SalesWorkspace::class)
            ->call('prepareQuickViewWebSale', 'ship')
            ->assertForbidden();

        Livewire::test(SalesWorkspace::class)
            ->call('prepareQuickViewWebSale', 'unexpected')
            ->assertForbidden();
    }

    public function test_web_sales_workspace_quick_view_rejects_missing_order(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(SalesWorkspace::class)
            ->call('openOrderQuickView', 'webSales', 99999999)
            ->assertNotFound();
    }

    public function test_sales_workspace_create_order_uses_native_modal_without_navigation(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($owner, true);

        Livewire::test(SalesWorkspace::class)
            ->assertSee('Create Sales Order')
            ->assertActionExists('createSalesOrder')
            ->assertActionVisible('createSalesOrder');
        $this->assertDatabaseCount('orders', 0);
    }
}
