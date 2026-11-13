<?php

namespace Tests\Feature\Orders;

use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ManualSourcingStatus;
use App\Enums\OrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\AccountingObligation;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\ManualSourcingShipmentPosting;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebSalesManualSourcingLine;
use App\Services\Orders\OrderService;
use App\Services\Orders\WebSalesService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ManualSourcingShipmentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_sourcing_shipment_is_atomic_idempotent_and_keeps_warehouse_balance_at_zero(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create();
        $order = $this->createOrder($owner, [$this->directItem($supplier, 'DIRECT-1')]);
        $key = (string) Str::uuid();

        $shipped = app(WebSalesService::class)->ship($order, $key, $owner);
        app(WebSalesService::class)->ship($shipped, $key, $owner);

        $this->assertSame(OrderStatus::Fulfilled, $shipped->refresh()->status);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertSame(1, ManualSourcingShipmentPosting::query()->count());
        $receipt = PurchaseReceipt::query()->sole();
        $this->assertSame('direct_to_customer', $receipt->custody_mode);
        $this->assertSame(0, ProductInventory::query()->sole()->available_quantity);
        $this->assertSame(0, ProductInventory::query()->sole()->reserved_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('order_fulfillments', 1);
        $this->assertDatabaseCount('order_fulfillment_items', 1);
        $this->assertSame('500.0000', OrderFulfillmentItem::query()->sole()->inventory_unit_cost);
        $this->assertSame('500.0000', AccountingObligation::query()->where('source_type', 'order_line_fulfillment')->sole()->unit_cost);
        $this->assertSame('AED', AccountingObligation::query()->where('source_type', 'order_line_fulfillment')->sole()->currency);
    }

    public function test_supplier_direct_shipping_accepts_an_on_spot_supplier_name_without_a_supplier_master(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $originalSupplierCount = Supplier::query()->count();
        $order = $this->createOrder($owner, [new OrderItemData(
            productId: 0,
            quantity: 1,
            sellingPrice: '1500.00',
            sourceType: 'manual_sourced',
            manualDescription: 'Demo direct sourced laptop with informal supplier',
            manualBrand: 'TPZ',
            manualCategory: 'Laptops',
            manualModel: 'DIRECT-FREE-NAME',
            sourceMode: 'supplier_direct',
            actualUnitCost: '500.00',
            purchaseCurrency: 'AED',
            purchasedOn: today()->toDateString(),
            supplierName: 'Example walk-in counter supplier',
        )]);
        $line = $order->manualSourcingLines()->sole();

        $this->assertNull($line->supplier_id);
        $this->assertSame('Example walk-in counter supplier', $line->metadata['supplier_name']);
        $this->assertSame($originalSupplierCount, Supplier::query()->count());

        $shipped = app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);

        $this->assertSame(OrderStatus::Fulfilled, $shipped->refresh()->status);
        $this->assertNull(Purchase::query()->sole()->supplier_id);
        $this->assertStringContainsString('Example walk-in counter supplier', Purchase::query()->sole()->notes);
        $this->assertSame($originalSupplierCount, Supplier::query()->count());
        $this->assertSame(0, ProductInventory::query()->sole()->available_quantity);
        $this->assertSame(1, ManualSourcingShipmentPosting::query()->count());
    }

    public function test_supplier_direct_shipping_accepts_no_supplier_like_quick_purchase(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $order = $this->createOrder($owner, [new OrderItemData(
            productId: 0,
            quantity: 1,
            sellingPrice: '1500.00',
            sourceType: 'manual_sourced',
            manualDescription: 'Demo direct sourced laptop with no supplier',
            manualBrand: 'TPZ',
            manualCategory: 'Laptops',
            manualModel: 'DIRECT-NONE',
            sourceMode: 'supplier_direct',
            actualUnitCost: '500.00',
            purchaseCurrency: 'AED',
            purchasedOn: today()->toDateString(),
        )]);

        $shipped = app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);

        $this->assertSame(OrderStatus::Fulfilled, $shipped->refresh()->status);
        $this->assertNull(Purchase::query()->sole()->supplier_id);
        $this->assertStringContainsString('Supplier not specified', Purchase::query()->sole()->notes);
        $this->assertSame(1, ManualSourcingShipmentPosting::query()->count());
    }

    public function test_failure_after_direct_receipt_rolls_back_every_shipment_record(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $order = $this->createOrder($owner, [$this->directItem(Supplier::factory()->create(), 'ROLLBACK-1')]);
        $orders = Mockery::mock(OrderService::class);
        $orders->shouldReceive('preallocateFulfillmentReferences')->once()->andReturn([
            'fulfillment_reference' => 'SOF-2026-TEST',
            'movement_references' => [],
            'posting_keys' => [],
            'upgrade_movement_references' => [],
            'upgrade_posting_keys' => [],
            'upgrade_execution_keys' => [],
        ]);
        $orders->shouldReceive('preallocateReservationReferences')->once()->andReturn([
            'reservationReferences' => [],
            'movementReferences' => [],
            'postingKeys' => [],
            'upgradePlans' => [],
            'componentReservationReferences' => [],
            'componentMovementReferences' => [],
            'componentPostingKeys' => [],
        ]);
        $orders->shouldReceive('reserveDraft')->once()->andThrow(new RuntimeException('Injected failure after direct receipt'));
        $this->app->instance(OrderService::class, $orders);

        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);
            $this->fail('Shipment must roll back when reservation fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after direct receipt', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::PendingReview, $order->refresh()->status);
        $this->assertSame(ManualSourcingStatus::PendingSourcing, $order->manualSourcingLines()->sole()->status);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('manual_sourcing_shipment_postings', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('order_line_fulfillments', 0);
        $this->assertDatabaseCount('order_line_fulfillment_events', 0);
        $this->assertDatabaseCount('accounting_obligations', 0);
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_two_manual_lines_create_distinct_receipts_and_accounting_without_physical_stock(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create();
        $order = $this->createOrder($owner, [
            $this->directItem($supplier, 'DIRECT-A', '500.00', 2),
            $this->directItem($supplier, 'DIRECT-B', '700.00'),
        ]);

        app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);

        $this->assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        $this->assertDatabaseCount('purchases', 2);
        $this->assertDatabaseCount('purchase_receipts', 2);
        $this->assertDatabaseCount('manual_sourcing_shipment_postings', 2);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->assertDatabaseCount('order_fulfillments', 1);
        $this->assertSame(2, AccountingObligation::query()->where('source_type', 'order_line_fulfillment')->count());
        $this->assertEqualsCanonicalizing(['500.0000', '700.0000'], AccountingObligation::query()->where('source_type', 'order_line_fulfillment')->pluck('unit_cost')->all());
        $this->assertTrue(PurchaseReceipt::query()->get()->every(fn (PurchaseReceipt $receipt): bool => $receipt->custody_mode === 'direct_to_customer'));
        $this->assertTrue(ProductInventory::query()->get()->every(fn (ProductInventory $inventory): bool => $inventory->available_quantity === 0 && $inventory->reserved_quantity === 0));
        $this->assertTrue(StockMovement::query()->get()->every(fn (StockMovement $movement): bool => $movement->available_delta === 0 && $movement->reserved_delta === 0 && $movement->damaged_delta === 0));
    }

    public function test_purchase_quick_receive_denial_prevents_supplier_direct_shipment_and_side_effects(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $order = $this->createOrder($admin, [$this->directItem(Supplier::factory()->create(), 'DENIED-1')]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => PurchasePermission::QuickReceive->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Manual Sourcing purchase authorization regression',
        ]);

        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $admin);
            $this->fail('An employee without QuickReceive may not create a direct receipt.');
        } catch (AuthorizationException) {
            $this->assertSame(OrderStatus::PendingReview, $order->refresh()->status);
        }

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('accounting_obligations', 0);
        $this->assertDatabaseCount('manual_sourcing_shipment_postings', 0);
    }

    public function test_mixed_order_consumes_only_existing_warehouse_stock_and_posts_direct_receipt(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $physical = Product::factory()->create(['selling_price' => '900.00']);
        $physicalInventory = ProductInventory::factory()->create([
            'product_id' => $physical->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'average_cost' => '300.0000',
        ]);
        $order = $this->createOrder($owner, [
            new OrderItemData($physical->id, 1, '900.00'),
            $this->directItem(Supplier::factory()->create(), 'MIXED-1', '450.00'),
        ]);

        app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);

        $this->assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        $this->assertSame(4, $physicalInventory->refresh()->available_quantity);
        $this->assertSame(0, $physicalInventory->reserved_quantity);
        $manualProductId = WebSalesManualSourcingLine::query()->sole()->product_id;
        $directInventory = ProductInventory::query()->where('product_id', $manualProductId)->sole();
        $this->assertSame(0, $directInventory->available_quantity);
        $this->assertSame(0, $directInventory->reserved_quantity);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('manual_sourcing_shipment_postings', 1);
        $this->assertSame(2, OrderFulfillmentItem::query()->count());
        $this->assertSame('300.0000', OrderFulfillmentItem::query()->where('product_id', $physical->id)->sole()->inventory_unit_cost);
        $this->assertSame('450.0000', OrderFulfillmentItem::query()->where('product_id', $manualProductId)->sole()->inventory_unit_cost);
        $this->assertSame('450.0000', AccountingObligation::query()->where('source_type', 'order_line_fulfillment')->sole()->unit_cost);
        $this->assertTrue(StockMovement::query()->where('product_id', $manualProductId)->get()->every(fn (StockMovement $movement): bool => $movement->available_delta === 0 && $movement->reserved_delta === 0));
    }

    public function test_supplier_direct_return_enters_existing_qc_and_restores_sellable_stock_after_pass(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $order = $this->createOrder($owner, [$this->directItem(Supplier::factory()->create(), 'RETURN-1')]);
        app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);
        $fulfilledItem = OrderFulfillmentItem::query()->sole();
        $this->assertSame('500.0000', $fulfilledItem->inventory_unit_cost);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            $warehouse->id,
            [['order_fulfillment_item_id' => $fulfilledItem->id, 'quantity' => 1, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $owner);

        app(ReceiveCustomerReturn::class)->handle($return, $owner);

        $inventory = ProductInventory::query()->where('product_id', $fulfilledItem->product_id)->sole();
        $this->assertSame(CustomerReturnStatus::QcPending, $return->refresh()->status);
        $this->assertSame(0, $inventory->refresh()->available_quantity);
        $this->assertSame(1, $inventory->qc_pending_quantity);
        app(InspectCustomerReturnItem::class)->handle(
            $return->items()->sole(),
            new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()),
            $owner,
        );

        $this->assertSame(CustomerReturnStatus::Completed, $return->refresh()->status);
        $this->assertSame(1, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertSame('500.0000', $inventory->average_cost);
        $this->assertSame('500.0000', $fulfilledItem->fresh()->inventory_unit_cost);
    }

    /** @param array<int, OrderItemData> $items */
    private function createOrder(User $actor, array $items): Order
    {
        return app(WebSalesService::class)->createConfirmed(new WebSalesOrderData(
            customerName: 'Direct customer',
            customerPhone: '+971501234567',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'Courier',
            trackingNumber: 'DIRECT-1',
            items: $items,
            idempotencyKey: (string) Str::uuid(),
            customerAddress: 'Dubai',
        ), $actor);
    }

    private function directItem(Supplier $supplier, string $model, string $cost = '500.00', int $quantity = 1): OrderItemData
    {
        return new OrderItemData(
            productId: 0,
            quantity: $quantity,
            sellingPrice: '1500.00',
            sourceType: 'manual_sourced',
            manualDescription: 'Direct sourced laptop '.$model,
            manualBrand: 'TPZ',
            manualCategory: 'Laptops',
            manualModel: $model,
            supplierId: $supplier->id,
            sourceMode: 'supplier_direct',
            actualUnitCost: $cost,
            purchaseCurrency: 'AED',
            purchasedOn: today()->toDateString(),
        );
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
