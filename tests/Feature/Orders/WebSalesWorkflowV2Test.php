<?php

namespace Tests\Feature\Orders;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\EmployeeRole;
use App\Enums\ProductTitleMode;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Filament\Resources\WebSalesOrders\Pages\CreateWebSalesOrder;
use App\Filament\Resources\WebSalesOrders\Pages\ViewWebSalesOrder;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\InventoryAllocationReservationLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Orders\WebSalesCustomerLookupService;
use App\Services\Orders\WebSalesService;
use App\Services\Orders\WebSalesTaxInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WebSalesWorkflowV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_web_sale_can_be_created_with_blank_address_and_detail_renders(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $account = $allocations->employeeAccount($owner->employee->id);
        $allocations->reconcile($inventory, $account, 1, $owner, 'Blank-address sale source');
        $this->actingAs($owner);

        Livewire::test(CreateWebSalesOrder::class)
            ->fillForm([
                'customer_name' => 'Customer without address', 'customer_phone' => '+971501112233',
                'customer_address' => '', 'web_sales_channel' => 'whatsapp',
                'delivery_type' => 'shop_pickup',
                'items' => [[
                    'product_id' => $product->id, 'quantity' => 1, 'selling_price' => '1500.00',
                    'allocation_sources' => [['account_id' => $account->id, 'quantity' => 1]],
                ]],
            ])->call('create')->assertHasNoFormErrors();

        $order = Order::query()->webSales()->sole();
        $this->assertNull($order->customer_address);
        Livewire::test(ViewWebSalesOrder::class, ['record' => $order->id])->assertOk();

        try {
            app(WebSalesTaxInvoiceService::class)->generateOrFind($order, $owner);
            $this->fail('Tax Invoice address validation should remain in place.');
        } catch (ValidationException $exception) {
            $this->assertSame('Add the customer address to this Web Sale before generating its Tax Invoice.', $exception->errors()['invoice'][0]);
        }
        $this->assertDatabaseCount('tax_invoices', 0);
    }

    public function test_web_sale_service_accepts_null_address(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        app(InventoryAllocationService::class)->ensureShadowCoverage($inventory, $owner);

        $order = app(WebSalesService::class)->createConfirmed($this->data($product, null, address: null), $owner);

        $this->assertNull($order->customer_address);
        $this->assertSame(1, $inventory->refresh()->reserved_quantity);
    }

    public function test_existing_customer_search_prefills_snapshots_without_updating_previous_sale(): void
    {
        [$owner] = $this->foundation();
        $previous = Order::query()->create([
            'reference' => 'SO-CUSTOMER-001', 'source' => 'manual', 'status' => 'fulfilled',
            'warehouse_id' => Warehouse::query()->where('code', 'MAIN')->value('id'),
            'web_sales_channel' => 'website', 'customer_name' => 'Ayesha Khan',
            'customer_phone' => '+971501112233', 'customer_address' => "Office 12\nDubai, UAE",
            'delivery_type' => 'courier', 'order_date' => today(), 'subtotal' => 1, 'discount_total' => 0,
            'vat_total' => 0, 'grand_total' => 1, 'handled_by_employee_id' => $owner->employee->id,
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        $lookup = app(WebSalesCustomerLookupService::class);

        $this->assertArrayHasKey($previous->id, $lookup->options($owner, 'Ayesha'));
        $this->assertArrayHasKey($previous->id, $lookup->options($owner, '1112233'));
        $this->assertSame([
            'name' => 'Ayesha Khan', 'phone' => '+971501112233', 'address' => "Office 12\nDubai, UAE",
        ], $lookup->details($owner, $previous->id));
        $this->assertSame('Ayesha Khan', $previous->refresh()->customer_name);
    }

    public function test_web_sale_uses_exact_split_stock_sources_independently_from_handler(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        $firstHolder = $this->user(EmployeeRole::Staff);
        $secondHolder = $this->user(EmployeeRole::Staff);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $first = $allocations->employeeAccount($firstHolder->employee->id);
        $second = $allocations->employeeAccount($secondHolder->employee->id);
        $allocations->reconcile($inventory, $first, 2, $owner, 'Web Sales test source');
        $allocations->reconcile($inventory, $second, 2, $owner, 'Web Sales test source');

        $order = app(WebSalesService::class)->createConfirmed($this->data($product, [
            $first->id => 1,
            $second->id => 2,
        ], quantity: 3), $owner);

        $this->assertSame($owner->employee->id, $order->handled_by_employee_id);
        $this->assertEqualsCanonicalizing([
            ['account_id' => $first->id, 'quantity' => 1],
            ['account_id' => $second->id, 'quantity' => 2],
        ], InventoryAllocationReservationLine::query()->get(['account_id', 'quantity'])->toArray());
        $this->assertSame(3, $inventory->refresh()->reserved_quantity);
    }

    public function test_missing_explicit_source_keeps_existing_shadow_system_fallback(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);

        app(WebSalesService::class)->createConfirmed($this->data($product, null, quantity: 2), $owner);

        $line = InventoryAllocationReservationLine::query()->sole();
        $this->assertSame($allocations->systemAccount()->id, $line->account_id);
        $this->assertSame(2, $line->quantity);
    }

    public function test_tax_invoice_generation_is_idempotent_and_preserves_web_sale_snapshot(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        app(InventoryAllocationService::class)->ensureShadowCoverage($inventory, $owner);
        $product->update(['website_title_override' => 'Customer-facing website title']);
        CompanyProfile::query()->create([
            'id' => 1, 'company_name_en' => 'Tech Point Zone', 'company_name_ar' => 'Tech Point Zone',
            'trn' => '100000000000001', 'address_en' => 'Dubai UAE', 'legal_statement_en' => 'Registered company.',
            'updated_by_user_id' => $owner->id,
        ]);
        $order = app(WebSalesService::class)->createConfirmed($this->data($product, null, address: 'Business Bay, Dubai'), $owner);
        $service = app(WebSalesTaxInvoiceService::class);

        $invoice = $service->generateOrFind($order, $owner, ProductTitleMode::Website);
        $retry = $service->generateOrFind($order, $owner, ProductTitleMode::Accounting);

        $this->assertSame($invoice->id, $retry->id);
        $this->assertSame($order->id, $invoice->source_order_id);
        $this->assertSame(ProductTitleMode::Website, $invoice->title_mode);
        $this->assertSame('Business Bay, Dubai', $invoice->customer_address);
        $this->assertSame($order->items->sole()->id, $invoice->items->sole()->source_order_item_id);
        $this->assertSame('Customer-facing website title', $invoice->items->sole()->description);
        $this->assertDatabaseCount('tax_invoices', 1);
        $this->actingAs($owner);
        Livewire::test(ViewWebSalesOrder::class, ['record' => $order->id])->assertSee('View Tax Invoice');
    }

    public function test_form_and_detail_render_new_customer_source_and_invoice_workflow(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $account = $allocations->employeeAccount($owner->employee->id);
        $allocations->reconcile($inventory, $account, 2, $owner, 'Web Sales form source');
        $this->actingAs($owner);

        Livewire::test(CreateWebSalesOrder::class)
            ->assertOk()->assertSee('Find Existing Customer')->assertSee('Stock Source / Consume From')
            ->assertSee('Summary & Actions')
            ->fillForm([
                'customer_name' => 'Web Customer', 'customer_phone' => '+971501112233',
                'customer_address' => 'Dubai, UAE', 'web_sales_channel' => 'whatsapp',
                'delivery_type' => 'courier', 'courier_name' => 'Aramex',
                'items' => [[
                    'product_id' => $product->id, 'quantity' => 1, 'selling_price' => '1500.00',
                    'allocation_sources' => [['account_id' => $account->id, 'quantity' => 1]],
                ]],
            ])->call('create')->assertHasNoFormErrors();

        $order = Order::query()->webSales()->sole();
        Livewire::test(ViewWebSalesOrder::class, ['record' => $order->id])
            ->assertOk()->assertSee('Dubai, UAE')->assertSee('Stock Consumption')->assertSee($account->name)
            ->assertSee('Generate Tax Invoice');
    }

    public function test_order_address_migration_is_additive(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'customer_address'));
    }

    public function test_tax_invoice_generation_enforces_web_sales_record_scope_server_side(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        app(InventoryAllocationService::class)->ensureShadowCoverage($inventory, $owner);
        $order = app(WebSalesService::class)->createConfirmed($this->data($product, null), $owner);
        $staff = $this->user(EmployeeRole::Staff);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->employee->id,
            'assigned_by_user_id' => $owner->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create([
            'assignment_id' => $assignment->id,
            'product_id' => $product->id,
        ]);

        $this->expectException(AuthorizationException::class);
        app(WebSalesTaxInvoiceService::class)->generateOrFind($order, $staff);
    }

    /** @return array{User, Warehouse, Product, ProductInventory} */
    private function foundation(): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create(['selling_price' => '1500.00']);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 10, 'reserved_quantity' => 0, 'average_cost' => '500.0000',
        ]);

        return [$owner, $warehouse, $product, $inventory];
    }

    /** @param array<int, int>|null $sources */
    private function data(Product $product, ?array $sources, int $quantity = 1, ?string $address = 'Dubai, UAE'): WebSalesOrderData
    {
        return new WebSalesOrderData(
            customerName: 'Test Customer', customerPhone: '+971501234567', channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier, courierName: 'Aramex', trackingNumber: 'AWB-101',
            items: [new OrderItemData($product->id, $quantity, '1500.00', allocationSources: $sources)],
            idempotencyKey: (string) Str::uuid(), customerAddress: $address,
        );
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
