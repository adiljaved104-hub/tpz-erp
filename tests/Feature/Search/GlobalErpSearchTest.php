<?php

namespace Tests\Feature\Search;

use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Filament\Resources\Products\ProductResource;
use App\Livewire\GlobalErpSearch;
use App\Models\Complaint;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderFulfillmentItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\SafetClaim;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Services\Search\GlobalSearchService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalErpSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_searches_exact_product_employee_order_task_purchase_grn_and_supplier_without_financial_payloads(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner, ['employee_id' => 'TPZ-0001', 'name' => 'Adil Hussain']);
        [, $asif] = $this->employee(EmployeeRole::Staff, ['employee_id' => 'TPZ-0005', 'name' => 'Asif Hameed']);
        $product = Product::factory()->create(['sku' => 'TPZ-000123', 'name' => 'HP EliteBook 840 G8', 'model' => '840 G8', 'cost_price' => 9999, 'selling_price' => 12000]);
        $warehouse = Warehouse::factory()->create();
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $order = $this->order($owner, $warehouse, $platform, 'SO-2026-000015', 'AMZ-EXT-500');
        $task = Task::query()->create(['reference' => 'TSK-2026-000118', 'title' => 'Check Noon listing', 'status' => 'assigned', 'priority' => 'high', 'assigned_employee_id' => $asif->id, 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);
        $supplier = Supplier::factory()->create(['name' => 'Laptop Wholesale UAE']);
        $purchase = Purchase::factory()->create(['reference' => 'PO-2026-000321', 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'created_by_user_id' => $owner->id]);
        $receipt = PurchaseReceipt::factory()->create(['reference' => 'GRN-2026-000444', 'purchase_id' => $purchase->id, 'warehouse_id' => $warehouse->id, 'received_by_user_id' => $owner->id]);

        $search = app(GlobalSearchService::class);
        $this->assertSame('TPZ-000123 · HP EliteBook 840 G8', $search->search($owner, 'TPZ-000123')->get('Products')->first()->label);
        $this->assertSame('TPZ-000123 · HP EliteBook 840 G8', $search->search($owner, 'EliteBook 840')->get('Products')->first()->label);
        $this->assertStringContainsString('Asif Hameed', $search->search($owner, 'TPZ-0005')->get('Employees')->first()->label);
        $this->assertStringContainsString('TPZ-0005', $search->search($owner, 'Asif Hameed')->get('Employees')->first()->label);
        $this->assertStringContainsString($order->reference, $search->search($owner, $order->reference)->get('Orders')->first()->label);
        $this->assertStringContainsString($order->reference, $search->search($owner, 'AMZ-EXT-500')->get('Orders')->first()->label);
        $this->assertStringContainsString($task->reference, $search->search($owner, 'Noon listing')->get('Tasks')->first()->label);
        $this->assertStringContainsString($purchase->reference, $search->search($owner, $purchase->reference)->get('Purchase Orders')->first()->label);
        $this->assertStringContainsString($receipt->reference, $search->search($owner, $receipt->reference)->get('GRNs / Receiving')->first()->label);
        $this->assertSame($supplier->name, $search->search($owner, 'Wholesale UAE')->get('Suppliers')->first()->label);

        $payload = json_encode($search->search($owner, 'TPZ-000123')->flatten()->all());
        $this->assertStringNotContainsString('9999', $payload);
        $this->assertStringNotContainsString('12000', $payload);
        $this->assertStringNotContainsString($asif->user->email, json_encode($search->search($owner, 'TPZ-0005')->flatten()->all()));
        $this->assertSame(ProductResource::getUrl('view', ['record' => $product]), $search->search($owner, 'TPZ-000123')->get('Products')->first()->url);
    }

    public function test_minimum_length_result_cap_and_employee_override_are_enforced_before_results(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$admin, $employee] = $this->employee(EmployeeRole::Admin);
        $restrictedProduct = Product::factory()->create(['name' => 'Common Search Laptop']);
        Product::factory()->count(8)->create(['name' => 'Common Search Laptop']);
        EmployeePermissionOverride::query()->create(['employee_id' => $employee->id, 'permission_key' => ProductPermission::View->value, 'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id, 'reason' => 'Search test']);

        $this->assertTrue(app(GlobalSearchService::class)->search($admin, 'x')->isEmpty());
        $this->assertNull(app(GlobalSearchService::class)->search($admin, 'Common Search')->get('Products'));
        $this->assertCount(GlobalSearchService::RESULTS_PER_GROUP, app(GlobalSearchService::class)->search($owner, 'Common Search')->get('Products'));
        $this->actingAs($admin)->get(ProductResource::getUrl('view', ['record' => $restrictedProduct]))->assertForbidden();
    }

    public function test_return_claim_warranty_and_complaint_references_are_searchable_without_financial_context(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        $product = Product::factory()->create(['sku' => 'TPZ-SVC-001', 'name' => 'Service Search Laptop']);
        $warehouse = Warehouse::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id]);
        $order = $this->order($owner, $warehouse, $platform, 'SO-SERVICE-001', 'EXT-SERVICE-001');
        $orderItem = OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'ordered_quantity' => 1, 'selling_price' => 100, 'line_total' => 100]);
        $fulfillment = OrderFulfillment::query()->create(['reference' => 'SOF-SEARCH-001', 'order_id' => $order->id, 'movement_group' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'fulfilled_by_user_id' => $owner->id, 'fulfilled_at' => now()]);
        $fulfillmentItem = OrderFulfillmentItem::query()->create(['order_fulfillment_id' => $fulfillment->id, 'order_item_id' => $orderItem->id, 'product_inventory_id' => $inventory->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'inventory_unit_cost' => 25, 'cogs_total' => 25, 'posting_key' => (string) Str::uuid(), 'created_at' => now()]);
        $return = CustomerReturn::query()->create(['reference' => 'RTN-SEARCH-001', 'order_id' => $order->id, 'marketplace_platform_id' => $platform->id, 'fulfillment_warehouse_id' => $warehouse->id, 'status' => 'draft', 'return_source' => 'manual', 'reported_at' => now(), 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);
        $returnItem = CustomerReturnItem::query()->create(['customer_return_id' => $return->id, 'order_item_id' => $orderItem->id, 'order_fulfillment_item_id' => $fulfillmentItem->id, 'product_id' => $product->id, 'sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name, 'fulfilled_quantity_snapshot' => 1, 'return_quantity' => 1, 'inventory_unit_cost' => 25, 'return_reason' => 'defective']);
        $damage = DamagedStockEvent::query()->create(['reference' => 'DMG-SEARCH-001', 'product_inventory_id' => $inventory->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'source' => 'customer_return', 'reason' => 'Damaged', 'occurred_at' => now(), 'reported_by_user_id' => $owner->id, 'status' => 'damaged', 'idempotency_key' => (string) Str::uuid()]);
        $claim = SafetClaim::query()->create(['reference' => 'CLM-SEARCH-001', 'marketplace_platform_id' => $platform->id, 'customer_return_id' => $return->id, 'customer_return_item_id' => $returnItem->id, 'damaged_stock_event_id' => $damage->id, 'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'source' => 'qc_damaged_customer_return', 'status' => 'needs_filing', 'claim_reason' => 'Damage claim', 'currency' => 'AED', 'idempotency_key' => (string) Str::uuid()]);
        $warranty = WarrantyRepair::query()->create(['reference' => 'WR-SEARCH-001', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'source' => 'manual', 'issue_description' => 'Repair issue', 'received_at' => now(), 'status' => 'received', 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id]);
        $complaint = Complaint::query()->create(['reference' => 'CMP-SEARCH-001', 'category' => 'other', 'description' => 'Complaint search fixture', 'status' => 'open', 'opened_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id]);

        $search = app(GlobalSearchService::class);
        $this->assertSame($return->reference, str($search->search($owner, $return->reference)->get('Returns')->first()->label)->before(' ·')->toString());
        $this->assertSame($claim->reference, $search->search($owner, $claim->reference)->get('Claims / Safe-T')->first()->label);
        $this->assertStringContainsString($warranty->reference, $search->search($owner, $warranty->reference)->get('Warranty')->first()->label);
        $this->assertStringContainsString($complaint->reference, $search->search($owner, $complaint->reference)->get('Complaints')->first()->label);
    }

    public function test_manager_task_and_employee_search_remain_team_scoped(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Marketing', 'status' => true]);
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$manager] = $this->employee(EmployeeRole::Manager, ['team_id' => $team->id]);
        [, $ownEmployee] = $this->employee(EmployeeRole::Staff, ['team_id' => $team->id, 'name' => 'Scoped Search Employee']);
        [, $otherEmployee] = $this->employee(EmployeeRole::Staff, ['team_id' => $otherTeam->id, 'name' => 'Scoped Search Outsider']);
        Task::query()->create(['reference' => 'TSK-SCOPE-OWN', 'title' => 'Scoped Search Task', 'status' => 'assigned', 'priority' => 'normal', 'assigned_employee_id' => $ownEmployee->id, 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);
        Task::query()->create(['reference' => 'TSK-SCOPE-OTHER', 'title' => 'Scoped Search Task', 'status' => 'assigned', 'priority' => 'normal', 'assigned_employee_id' => $otherEmployee->id, 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);

        $taskLabels = app(GlobalSearchService::class)->search($manager, 'Scoped Search Task')->get('Tasks')->pluck('label')->join(' ');
        $employeeLabels = app(GlobalSearchService::class)->search($manager, 'Scoped Search')->get('Employees')->pluck('label')->join(' ');
        $this->assertStringContainsString('TSK-SCOPE-OWN', $taskLabels);
        $this->assertStringNotContainsString('TSK-SCOPE-OTHER', $taskLabels);
        $this->assertStringContainsString('Scoped Search Employee', $employeeLabels);
        $this->assertStringNotContainsString('Scoped Search Outsider', $employeeLabels);
    }

    public function test_staff_task_search_is_limited_to_their_own_assignment(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staffUser, $staff] = $this->employee(EmployeeRole::Staff);
        [, $otherStaff] = $this->employee(EmployeeRole::Staff);
        Task::query()->create(['reference' => 'TSK-STAFF-OWN', 'title' => 'Staff scoped search', 'status' => 'assigned', 'priority' => 'normal', 'assigned_employee_id' => $staff->id, 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);
        Task::query()->create(['reference' => 'TSK-STAFF-OTHER', 'title' => 'Staff scoped search', 'status' => 'assigned', 'priority' => 'normal', 'assigned_employee_id' => $otherStaff->id, 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);

        $labels = app(GlobalSearchService::class)->search($staffUser, 'Staff scoped search')->get('Tasks')->pluck('label')->join(' ');

        $this->assertStringContainsString('TSK-STAFF-OWN', $labels);
        $this->assertStringNotContainsString('TSK-STAFF-OTHER', $labels);
    }

    public function test_header_palette_renders_no_results_and_keyboard_hook(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        Product::factory()->create(['sku' => 'TPZ-PALETTE-01', 'name' => 'Palette Laptop']);
        $this->actingAs($owner);

        Livewire::test(GlobalErpSearch::class)
            ->assertSee('Search ERP...')
            ->assertSeeHtml('ctrl.k')
            ->assertSeeHtml('x-teleport="body"')
            ->assertSeeHtml('role="dialog"')
            ->set('query', 'TPZ-PALETTE-01')
            ->assertSee('Products')
            ->assertSee('TPZ-PALETTE-01 · Palette Laptop')
            ->set('query', 'zz-no-authorized-result')
            ->assertSee('No authorized results found.');
    }

    public function test_filament_default_global_search_is_disabled_to_prevent_a_duplicate_header_control(): void
    {
        $this->assertNull(Filament::getPanel('admin')->getGlobalSearchProvider());
    }

    public function test_admin_topbar_renders_one_erp_launcher_without_filament_default_search_markup(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);

        $response = $this->actingAs($owner)->get('/admin');

        $response->assertOk()
            ->assertSee('data-testid="global-erp-search"', false)
            ->assertDontSee('fi-global-search-ctn', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="global-erp-search"'));
    }

    /** @return array{User, Employee} */
    private function employee(EmployeeRole $role, array $attributes = []): array
    {
        $employee = Employee::factory()->role($role)->create($attributes);

        return [$employee->user, $employee];
    }

    private function order(User $owner, Warehouse $warehouse, MarketplacePlatform $platform, string $reference, string $external): Order
    {
        return Order::query()->create(['reference' => $reference, 'source' => 'marketplace', 'status' => 'draft', 'warehouse_id' => $warehouse->id, 'marketplace_platform_id' => $platform->id, 'external_order_number' => $external, 'order_date' => now()->toDateString(), 'subtotal' => 0, 'discount_total' => 0, 'vat_total' => 0, 'grand_total' => 0, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id]);
    }
}
