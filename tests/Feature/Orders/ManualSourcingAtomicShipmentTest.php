<?php

namespace Tests\Feature\Orders;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\Employee;
use App\Models\OrderFulfillmentItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\WebSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualSourcingAtomicShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_order_ships_with_actual_purchase_and_direct_grn_without_physical_warehouse_stock(): void
    {
        $owner = $this->owner();
        $supplier = Supplier::factory()->create();
        $service = app(WebSalesService::class);
        $order = $service->createConfirmed($this->orderData($supplier), $owner);
        $this->assertSame(OrderStatus::PendingReview, $order->status);

        $shipped = $service->ship($order, (string) Str::uuid(), $owner);
        $this->assertSame(OrderStatus::Fulfilled, $shipped->status);

        $manual = $shipped->manualSourcingLines()->sole();
        $purchase = $manual->purchase;
        $grn = $manual->purchaseReceipt;
        $this->assertNotNull($purchase);
        $this->assertNotNull($grn);
        $this->assertSame($purchase->id, $grn->purchase_id);
        $this->assertSame('manual_direct', $purchase->entry_type->value);
        $this->assertSame('supplier_customer_direct', $grn->fulfillment_type);
        $this->assertSame($manual->order_item_id, $grn->source_order_item_id);
        $this->assertSame(2, $grn->items()->sum('accepted_quantity'));
        $this->assertSame('250.0000', $manual->inventory_unit_cost_aed);

        $inventory = ProductInventory::query()->where('product_id', $manual->product_id)->where('warehouse_id', $order->warehouse_id)->sole();
        $this->assertSame(0, $inventory->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertSame(0, DB::table('direct_fulfillment_stock_events')->where('order_item_id', $manual->order_item_id)->sum('quantity_delta'));
        $this->assertSame(2, DB::table('direct_fulfillment_stock_events')->where('order_item_id', $manual->order_item_id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('product_id', $manual->product_id)->count());

        $item = OrderFulfillmentItem::query()->where('order_item_id', $manual->order_item_id)->sole();
        $this->assertSame('supplier_customer_direct', $item->fulfillment_type);
        $this->assertSame('500.0000', $item->cogs_total);
        $this->assertDatabaseHas('accounting_obligations', [
            'source_type' => 'purchase', 'source_id' => $purchase->id, 'quantity' => 2, 'currency' => 'AED',
        ]);
    }

    public function test_repeated_mark_shipped_does_not_duplicate_purchase_grn_or_virtual_stock(): void
    {
        $owner = $this->owner();
        $order = app(WebSalesService::class)->createConfirmed($this->orderData(Supplier::factory()->create()), $owner);

        $service = app(WebSalesService::class);
        $service->ship($order, (string) Str::uuid(), $owner);
        $service->ship($order->refresh(), (string) Str::uuid(), $owner);

        $this->assertSame(1, Purchase::query()->count());
        $this->assertSame(1, PurchaseReceipt::query()->count());
        $this->assertSame(2, DB::table('direct_fulfillment_stock_events')->count());
        $this->assertSame(1, OrderFulfillmentItem::query()->count());
    }

    public function test_if_existing_stock_line_cannot_be_reserved_purchase_and_grn_roll_back(): void
    {
        $owner = $this->owner();
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();
        $data = $this->orderData($supplier, [
            $this->manualLine($supplier),
            new OrderItemData($product->id, 1, '500.00'),
        ]);
        $order = app(WebSalesService::class)->createConfirmed($data, $owner);

        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);
            $this->fail('Expected insufficient warehouse stock to abort automatic purchase and GRN.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('purchases', 0);
            $this->assertDatabaseCount('purchase_receipts', 0);
            $this->assertDatabaseCount('direct_fulfillment_stock_events', 0);
            $this->assertSame(OrderStatus::PendingReview, $order->refresh()->status);
            $this->assertNull($order->manualSourcingLines()->sole()->purchase_id);
        }
    }

    public function test_direct_purchased_order_uses_existing_company_return_and_qc_pass_flow(): void
    {
        $owner = $this->owner();
        $order = app(WebSalesService::class)->createConfirmed($this->orderData(Supplier::factory()->create()), $owner);
        $shipped = app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);
        $manual = $shipped->manualSourcingLines()->sole();
        $item = OrderFulfillmentItem::query()->where('order_item_id', $manual->order_item_id)->sole();

        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            orderId: $shipped->id,
            receivingWarehouseId: $shipped->warehouse_id,
            items: [[
                'order_fulfillment_item_id' => $item->id,
                'quantity' => 1,
                'return_reason' => 'other',
            ]],
            idempotencyKey: (string) Str::uuid(),
        ), $owner);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        app(InspectCustomerReturnItem::class)->handle(
            $return->items()->sole(),
            new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()),
            $owner,
        );

        $inventory = ProductInventory::query()->where('product_id', $manual->product_id)->where('warehouse_id', $shipped->warehouse_id)->sole();
        $this->assertSame(1, $inventory->available_quantity);
        $this->assertSame(0, DB::table('direct_fulfillment_stock_events')->where('order_item_id', $manual->order_item_id)->sum('quantity_delta'));
        $this->assertSame('250.0000', $item->refresh()->inventory_unit_cost);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function manualLine(Supplier $supplier): OrderItemData
    {
        return new OrderItemData(
            productId: 0,
            quantity: 2,
            sellingPrice: '700.00',
            sourceType: 'manual_sourced',
            manualDescription: 'Manual supplier purchase test laptop',
            manualCondition: 'new',
            supplierId: $supplier->id,
            sourceMode: 'supplier_direct',
            autoPurchaseOnShip: true,
            manualPurchaseUnitCost: '250.0000',
            manualPurchaseCurrency: 'AED',
            manualFxRateToAed: '1.00000000',
            manualPurchaseDate: now()->toDateString(),
        );
    }

    private function orderData(Supplier $supplier, ?array $items = null): WebSalesOrderData
    {
        return new WebSalesOrderData(
            customerName: 'Manual Purchase Customer',
            customerPhone: '+971501122334',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'Supplier Dispatch',
            trackingNumber: 'DIRECT-SUPPLIER-TEST',
            items: $items ?? [$this->manualLine($supplier)],
            idempotencyKey: (string) Str::uuid(),
        );
    }
}
