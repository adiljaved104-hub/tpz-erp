<?php

namespace Tests\Feature\Orders;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\WebSalesService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualSourcingShipmentReferenceGuardMySqlTest extends TestCase
{
    use DatabaseMigrations;

    public function test_supplier_direct_shipping_succeeds_with_production_reference_guard_enabled(): void
    {
        config(['reference_sequences.enforce_transaction_guard' => true]);
        $this->assertSame(0, DB::transactionLevel());

        $owner = $this->owner();
        $order = $this->createOrder($owner, Supplier::factory()->create());

        $shipped = app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);

        $this->assertSame(OrderStatus::Fulfilled, $shipped->refresh()->status);
        $this->assertDatabaseCount('order_fulfillments', 1);
        $this->assertDatabaseCount('manual_sourcing_shipment_postings', 1);
    }

    public function test_reserved_warehouse_shipping_succeeds_with_production_reference_guard_enabled(): void
    {
        config(['reference_sequences.enforce_transaction_guard' => true]);
        $owner = $this->owner();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create(['selling_price' => '1500.00']);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 2,
            'reserved_quantity' => 0,
            'average_cost' => '500.0000',
        ]);
        $order = app(WebSalesService::class)->createConfirmed(new WebSalesOrderData(
            customerName: 'Reference guard warehouse customer',
            customerPhone: '+971501234567',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'Courier',
            trackingNumber: 'REFERENCE-GUARD-WAREHOUSE-1',
            items: [new OrderItemData($product->id, 1, '1500.00')],
            idempotencyKey: (string) Str::uuid(),
            customerAddress: 'Dubai',
        ), $owner);

        $shipped = app(WebSalesService::class)->ship($order, (string) Str::uuid(), $owner);

        $this->assertSame(OrderStatus::Fulfilled, $shipped->refresh()->status);
        $this->assertSame(1, $inventory->refresh()->available_quantity);
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function createOrder(User $owner, Supplier $supplier): Order
    {
        return app(WebSalesService::class)->createConfirmed(new WebSalesOrderData(
            customerName: 'Reference guard customer',
            customerPhone: '+971501234567',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'Courier',
            trackingNumber: 'REFERENCE-GUARD-1',
            items: [new OrderItemData(
                productId: 0,
                quantity: 1,
                sellingPrice: '1500.00',
                sourceType: 'manual_sourced',
                manualDescription: 'Reference guard direct sourced laptop',
                manualBrand: 'TPZ',
                manualCategory: 'Laptops',
                manualModel: 'REFERENCE-GUARD-1',
                supplierId: $supplier->id,
                sourceMode: 'supplier_direct',
                actualUnitCost: '500.00',
                purchaseCurrency: 'AED',
                purchasedOn: today()->toDateString(),
            )],
            idempotencyKey: (string) Str::uuid(),
            customerAddress: 'Dubai',
        ), $owner);
    }
}
