<?php

namespace Tests\Feature\Orders;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\AccountingObligation;
use App\Models\Employee;
use App\Models\ManualSourcingShipmentPosting;
use App\Models\ProductInventory;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\WebSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualSourcingShipmentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_sourcing_shipment_is_atomic_idempotent_and_keeps_warehouse_balance_at_zero(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $owner->refresh();
        $supplier = Supplier::factory()->create();
        $key = (string) Str::uuid();
        $order = app(WebSalesService::class)->createConfirmed(new WebSalesOrderData(
            customerName: 'Direct customer', customerPhone: '+971501234567', channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier, courierName: 'Courier', trackingNumber: 'DIRECT-1',
            items: [new OrderItemData(
                productId: 0, quantity: 1, sellingPrice: '1500.00', sourceType: 'manual_sourced',
                manualDescription: 'Direct sourced laptop', manualBrand: 'TPZ', manualModel: 'DIRECT-1',
                supplierId: $supplier->id, sourceMode: 'supplier_direct', actualUnitCost: '500.00',
                purchaseCurrency: 'AED', purchasedOn: today()->toDateString(),
            )],
            idempotencyKey: (string) Str::uuid(), customerAddress: 'Dubai',
        ), $owner);

        $shipped = app(WebSalesService::class)->ship($order, $key, $owner);
        app(WebSalesService::class)->ship($shipped, $key, $owner);

        $this->assertSame(OrderStatus::Fulfilled, $shipped->refresh()->status);
        $this->assertSame(1, ManualSourcingShipmentPosting::query()->count());
        $receipt = PurchaseReceipt::query()->sole();
        $this->assertSame('direct_to_customer', $receipt->custody_mode);
        $this->assertSame(0, ProductInventory::query()->sole()->available_quantity);
        $this->assertSame(0, ProductInventory::query()->sole()->reserved_quantity);
        $this->assertSame('500.0000', AccountingObligation::query()->where('source_type', 'order_line_fulfillment')->sole()->unit_cost);
        $this->assertSame('AED', AccountingObligation::query()->where('source_type', 'order_line_fulfillment')->sole()->currency);
    }
}
