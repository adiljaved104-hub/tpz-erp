<?php

namespace Tests\Feature\Orders;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\ManualSourcingShipmentPosting;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\StockMovement;
use App\Services\Orders\ManualSourcingShipmentService;
use App\Services\Orders\WebSalesService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Intentionally no transactional reset trait: CI migrates this disposable database
 * first, and runs this test before the transaction-wrapped regression cohort.
 */
class SupplierDirectShippingProductionGuardMySqlTest extends TestCase
{
    public function test_staff_shipping_keeps_production_reference_guard_active_and_materialization_non_standalone(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('tpz_supplier_direct_shipping_guard_test', DB::connection()->getDatabaseName());
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(collect(Schema::getIndexes('manual_sourcing_shipment_postings'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['idempotency_key']));
        config(['reference_sequences.enforce_transaction_guard' => true]);

        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $staff = Employee::factory()->role(EmployeeRole::Staff)->create()->user;
        $product = Product::factory()->create();
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
        foreach (['order.fulfill', 'web_sales.supplier_direct_ship'] as $key) {
            EmployeePermissionOverride::query()->create(['employee_id' => $staff->employee->id, 'permission_key' => $key, 'effect' => 'allow', 'granted_by_user_id' => $owner->id, 'reason' => 'Production reference guard regression']);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $order = app(WebSalesService::class)->createConfirmed(new WebSalesOrderData(
            'Test Customer', '+971500000000', WebSalesChannel::WhatsApp,
            WebSalesDeliveryType::Courier, 'Test Courier', null,
            [new OrderItemData($product->id, 1, '1050.00', sourceType: 'manual_sourced', sourceMode: 'supplier_direct', actualUnitCost: '100.00', purchaseCurrency: 'AED', purchasedOn: today()->toDateString())],
            (string) Str::uuid(), customerAddress: 'Dubai',
        ), $staff);
        try {
            app(ManualSourcingShipmentService::class)->materialize($order, (string) Str::uuid(), $staff, []);
            $this->fail('Materialization must not be a standalone purchasing operation.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('enclosing Web Sales shipment transaction', $exception->getMessage());
        }
        $key = (string) Str::uuid();
        $shipped = app(WebSalesService::class)->ship($order, $key, $staff);
        app(WebSalesService::class)->ship($shipped, $key, $staff);
        $this->assertSame(OrderStatus::Fulfilled, $order->fresh()->status);
        $this->assertSame(1, ManualSourcingShipmentPosting::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, $order->fulfillment()->count());
        $posting = ManualSourcingShipmentPosting::query()->where('order_id', $order->id)->sole();
        $receipt = PurchaseReceipt::query()->findOrFail($posting->purchase_receipt_id);
        $this->assertSame(2, StockMovement::query()->where('movement_group', $receipt->movement_group)->count());
        $this->assertTrue(config('reference_sequences.enforce_transaction_guard'));
        $this->assertSame(0, DB::transactionLevel());
        try {
            DB::transaction(fn () => app(ReferenceSequenceService::class)->next('supplier-direct-guard-must-reject'));
            $this->fail('The production guard must still reject in-transaction references.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('References must be reserved before the business transaction', $exception->getMessage());
        }
    }
}
