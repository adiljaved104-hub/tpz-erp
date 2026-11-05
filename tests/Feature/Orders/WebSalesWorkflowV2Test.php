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
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Supplier;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Orders\WebSalesCustomerLookupService;
use App\Services\Orders\ManualSourcingService;
use App\Services\Orders\WebSalesService;
use App\Services\Orders\WebSalesTaxInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Tests\TestCase;

class WebSalesWorkflowV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_item_layout_and_compact_selected_labels_preserve_detailed_search_and_multiple_rows(): void
    {
        [$owner, , $product] = $this->foundation();
        $product->update([
            'name' => 'Lenovo Demo Book 14-inch Business Laptop with LongTitleSearchMarker and extensive marketplace specifications',
            'accounting_title_override' => 'Lenovo Demo Book 14 16GB/512GB',
        ]);
        $this->actingAs($owner);
        $page = Livewire::test(CreateWebSalesOrder::class)->fillForm(['items' => [
            ['product_id' => $product->id, 'quantity' => 1, 'selling_price' => '1500.00'],
            ['product_id' => $product->id, 'quantity' => 2, 'selling_price' => '1500.00'],
        ]])->assertOk();
        $fields = collect($page->instance()->form->getFlatFields(withHidden: true));
        $products = $fields->filter(fn ($field): bool => $field->getName() === 'product_id');
        $this->assertCount(2, $products);
        foreach ($products as $field) {
            $this->assertSame($product->sku.' · Lenovo Demo Book 14 16GB/512GB', $field->getOptionLabel());
            $this->assertArrayHasKey($product->id, $field->getSearchResults('LongTitleSearchMarker'));
            $this->assertSame(12, $field->getColumnSpan('lg'));
            $this->assertSame(6, $field->getColumnSpan('md'));
            $this->assertSame(1, $field->getColumnSpan('default'));
        }
        $items = $fields->first(fn ($field): bool => $field->getName() === 'items');
        $this->assertSame(12, $items->getColumns('lg'));
        $this->assertSame(6, $items->getColumns('md'));
        $this->assertSame(1, $items->getColumns('default'));
        $price = $fields->first(fn ($field): bool => $field->getName() === 'selling_price');
        $this->assertSame(4, $price->getColumnSpan('lg'));
        $source = $fields->first(fn ($field): bool => $field->getName() === 'allocation_sources');
        $this->assertSame('full', $source->getColumnSpan('default'));
        $this->assertSame(1, $source->getColumns('default'));
        $page->assertSee('AED 1,500.00')->assertSee('AED 3,000.00');
    }

    public function test_manual_sourcing_uses_master_brand_category_options_and_full_width_fields(): void
    {
        [$owner] = $this->foundation();
        $brand = ProductBrand::factory()->create(['name' => 'UAT Brand', 'status' => true]);
        $category = ProductCategory::factory()->create(['name' => 'UAT Laptops', 'status' => true]);

        $this->actingAs($owner);
        $page = Livewire::test(CreateWebSalesOrder::class)->fillForm(['items' => [
            ['source_type' => 'manual_sourced', 'manual_description' => 'UAT new laptop', 'manual_brand' => $brand->name, 'manual_category' => $category->name],
        ]])->assertOk();
        $fields = collect($page->instance()->form->getFlatFields(withHidden: true));
        $brandField = $fields->first(fn ($field): bool => $field->getName() === 'manual_brand');
        $categoryField = $fields->first(fn ($field): bool => $field->getName() === 'manual_category');
        $description = $fields->first(fn ($field): bool => $field->getName() === 'manual_description');

        $this->assertInstanceOf(Select::class, $brandField);
        $this->assertInstanceOf(Select::class, $categoryField);
        $this->assertSame($brand->name, $brandField->getOptions()[$brand->name]);
        $this->assertSame($category->name, $categoryField->getOptions()[$category->name]);
        $this->assertSame(6, $description->getColumnSpan('md'));
        $this->assertSame(12, $description->getColumnSpan('lg'));
    }

    public function test_manual_sourcing_supplier_is_optional_in_form_and_can_be_set_before_purchase_posting(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        $inventory->forceFill(['available_quantity' => 0, 'reserved_quantity' => 0])->save();
        $supplier = Supplier::factory()->create(['status' => true]);

        $this->actingAs($owner);
        $page = Livewire::test(CreateWebSalesOrder::class)->fillForm([
            'customer_name' => 'Manual sourcing supplier optional',
            'customer_phone' => '+971501112233',
            'web_sales_channel' => 'whatsapp',
            'delivery_type' => 'shop_pickup',
            'items' => [[
                'source_type' => 'manual_sourced',
                'product_id' => $product->id,
                'quantity' => 1,
                'selling_price' => '1800.00',
                'supplier_id' => null,
                'actual_unit_cost' => '1000.00',
                'purchased_on' => today()->toDateString(),
            ]],
        ]);
        $fields = collect($page->instance()->form->getFlatFields(withHidden: true));
        $supplierField = $fields->first(fn ($field): bool => $field->getName() === 'supplier_id');
        $this->assertNotNull($supplierField);
        $this->assertFalse($supplierField->isRequired());

        $page->call('create')->assertHasNoFormErrors();
        $order = Order::query()->webSales()->sole();
        $line = $order->manualSourcingLines()->sole();
        $this->assertNull($line->supplier_id);
        $this->assertSame('pending_review', $order->status->value);
        $this->assertSame(0, (int) $inventory->refresh()->available_quantity);

        $updated = app(ManualSourcingService::class)->assignSupplier($line, $supplier->id, $owner);
        $this->assertSame($supplier->id, (int) $updated->supplier_id);
        $this->assertSame($supplier->id, (int) $line->refresh()->supplier_id);

        // Once sourcing has started, the recorded purchase provenance must
        // not be silently changed from the Web Sale.
        $line->forceFill(['status' => 'ready_for_allocation'])->save();
        try {
            app(ManualSourcingService::class)->assignSupplier($line, $supplier->id, $owner);
            $this->fail('An already-posted sourcing line must be immutable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_id', $exception->errors());
        }
        $this->assertSame($supplier->id, (int) $line->refresh()->supplier_id);
    }

    public function test_manual_supplier_name_can_be_entered_on_web_sale_without_creating_a_supplier_master(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        $inventory->forceFill(['available_quantity' => 0, 'reserved_quantity' => 0])->save();
        $supplierCount = Supplier::query()->count();

        $this->actingAs($owner);
        $page = Livewire::test(CreateWebSalesOrder::class)->fillForm([
            'customer_name' => 'Quick supplier name entry test',
            'customer_phone' => '+971501112233',
            'web_sales_channel' => 'whatsapp',
            'delivery_type' => 'shop_pickup',
            'items' => [[
                'source_type' => 'manual_sourced',
                'product_id' => $product->id,
                'quantity' => 1,
                'selling_price' => '1500.00',
                'supplier_id' => null,
                'supplier_name' => 'Counter supplier, no master record',
                'actual_unit_cost' => '1000.00',
                'purchased_on' => today()->toDateString(),
            ]],
        ]);

        $fields = collect($page->instance()->form->getFlatFields(withHidden: true));
        $this->assertNotNull($fields->first(fn ($field): bool => $field->getName() === 'supplier_name'));

        $page->call('create')->assertHasNoFormErrors();

        $line = Order::query()->webSales()->sole()->manualSourcingLines()->sole();
        $this->assertNull($line->supplier_id);
        $this->assertSame('Counter supplier, no master record', $line->metadata['supplier_name']);
        $this->assertSame($supplierCount, Supplier::query()->count());
        $this->assertSame(0, (int) $inventory->refresh()->available_quantity);
    }

    public function test_manual_sourcing_reuses_existing_catalog_sku_without_touching_zero_stock(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        $inventory->forceFill(['available_quantity' => 0, 'reserved_quantity' => 0])->save();
        $initialProducts = Product::query()->count();

        $this->actingAs($owner);
        $page = Livewire::test(CreateWebSalesOrder::class)->fillForm(['items' => [[
            'source_type' => 'manual_sourced',
            'product_id' => $product->id,
            'quantity' => 2,
            'selling_price' => '1700.00',
        ]]])->assertOk();
        $fields = collect($page->instance()->form->getFlatFields(withHidden: true));
        $productField = $fields->first(fn ($field): bool => $field->getName() === 'product_id');
        $this->assertArrayHasKey($product->id, $productField->getSearchResults($product->sku));

        $data = new WebSalesOrderData(
            customerName: 'Manual sourcing catalog reuse',
            customerPhone: '+971501112233',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'UAT courier',
            trackingNumber: 'UAT-123',
            items: [new OrderItemData(
                $product->id, 2, '1700.00',
                sourceType: 'manual_sourced',
                supplierId: null,
                sourceMode: 'supplier_direct',
                actualUnitCost: '1200.0000',
                purchaseCurrency: 'AED',
                purchasedOn: today()->toDateString(),
            )],
            idempotencyKey: (string) Str::uuid(),
        );

        $order = app(WebSalesService::class)->createConfirmed($data, $owner);
        $line = $order->manualSourcingLines()->sole();
        $orderItem = $order->items()->sole();

        $this->assertSame($initialProducts, Product::query()->count());
        $this->assertSame($product->id, (int) $line->product_id);
        $this->assertSame($product->sku, $orderItem->sku);
        $this->assertSame($product->name, $line->description);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame('1200.0000', (string) $line->actual_unit_cost);
        $this->assertSame(0, (int) $inventory->refresh()->available_quantity);
        $this->assertSame(0, (int) $inventory->reserved_quantity);
    }

    public function test_manual_sourcing_does_not_accept_an_unknown_existing_product_id(): void
    {
        [$owner] = $this->foundation();
        $data = new WebSalesOrderData(
            customerName: 'Invalid catalog reuse',
            customerPhone: '+971501112233',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'UAT courier',
            trackingNumber: 'UAT-124',
            items: [new OrderItemData(99999999, 1, '1500.00', sourceType: 'manual_sourced')],
            idempotencyKey: (string) Str::uuid(),
        );

        $this->expectException(ValidationException::class);
        app(WebSalesService::class)->createConfirmed($data, $owner);
    }

    public function test_web_sale_details_can_be_edited_before_shipping_without_moving_inventory(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        app(InventoryAllocationService::class)->ensureShadowCoverage($inventory, $owner);
        $sale = app(WebSalesService::class)->createConfirmed($this->data($product, null), $owner);
        $beforeAvailable = (int) $inventory->refresh()->available_quantity;
        $beforeReserved = (int) $inventory->reserved_quantity;
        $beforeLineTotal = (string) $sale->items()->sole()->line_total;

        $this->actingAs($owner);
        Livewire::test(ViewWebSalesOrder::class, ['record' => $sale->id])
            ->assertActionVisible('editWebSale');
        $updated = app(WebSalesService::class)->updateDetails($sale, [
            'customer_name' => 'Corrected Customer',
            'customer_phone' => '+971 50 111 2233',
            'customer_address' => 'New Office, Dubai',
            'notes' => 'Customer confirmed updated delivery details',
        ], $owner);

        $this->assertSame('Corrected Customer', $updated->customer_name);
        $this->assertSame('+971501112233', $updated->customer_phone);
        $this->assertSame('New Office, Dubai', $updated->customer_address);
        $this->assertSame($beforeAvailable, (int) $inventory->refresh()->available_quantity);
        $this->assertSame($beforeReserved, (int) $inventory->reserved_quantity);
        $this->assertSame($beforeLineTotal, (string) $updated->items()->sole()->line_total);
        $this->assertDatabaseCount('tax_invoices', 0);

        Livewire::test(ViewWebSalesOrder::class, ['record' => $sale->id])
            ->assertSee('Corrected Customer')
            ->assertActionVisible('amendWebSaleItems');
    }

    public function test_web_sale_edit_is_locked_after_shipment_and_does_not_change_customer_snapshot(): void
    {
        [$owner, , $product, $inventory] = $this->foundation();
        app(InventoryAllocationService::class)->ensureShadowCoverage($inventory, $owner);
        $sale = app(WebSalesService::class)->createConfirmed($this->data($product, null), $owner);
        $originalName = $sale->customer_name;
        $sale->forceFill(['status' => \App\Enums\OrderStatus::Fulfilled])->save();

        $this->actingAs($owner);
        Livewire::test(ViewWebSalesOrder::class, ['record' => $sale->id])
            ->assertActionHidden('editWebSale')
            ->assertActionHidden('amendWebSaleItems');

        try {
            app(WebSalesService::class)->updateDetails($sale, [
                'customer_name' => 'Should Never Save',
                'customer_phone' => '+971501112233',
            ], $owner);
            $this->fail('Shipped Web Sale customer snapshot must be locked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        $this->assertSame($originalName, $sale->refresh()->customer_name);
    }

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
