<?php

namespace Tests\Feature\Returns;

use App\Actions\Orders\SaveAsShippedOrder;
use App\Actions\Returns\CancelCustomerReturn;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeeRole;
use App\Enums\StockMovementType;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\ReferenceSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_company_return_receives_to_qc_then_split_classifies_once_with_original_cost(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(5, 3, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 3);
        $this->assertMatchesRegularExpression('/^RTN-\d{4}-\d{6}$/', $return->reference);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        $inventory->refresh();
        $this->assertSame(CustomerReturnStatus::QcPending, $return->refresh()->status);
        $this->assertNotNull($return->received_at);
        $this->assertSame(2, $inventory->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertSame(3, $inventory->qc_pending_quantity);
        $this->assertSame('300.0000', $inventory->qc_pending_value);
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertDatabaseCount('damaged_stock_events', 0);
        $this->assertDatabaseCount('safet_claims', 0);
        $receipt = StockMovement::query()->where('movement_type', StockMovementType::CustomerReturnCompanyReceipt)->sole();
        $this->assertSame(0, $receipt->available_delta);
        $this->assertSame(3, $receipt->bucketChanges()->sole()->quantity_delta);

        app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(2, 1, (string) Str::uuid()), $owner);
        $inventory->refresh();
        $return->refresh();
        $this->assertSame(4, $inventory->available_quantity);
        $this->assertSame(1, $inventory->damaged_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertSame('0.0000', $inventory->qc_pending_value);
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertSame(CustomerReturnStatus::Completed, $return->status);
        $this->assertDatabaseCount('customer_return_inspections', 2);
        $this->assertDatabaseHas('activity_logs', ['event' => 'customer_return.completed', 'subject_id' => $return->id]);
    }

    public function test_weighted_average_uses_original_fulfilment_cost_and_does_not_change_cogs(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(10, 2, '200.0000');
        $cogs = $order->fulfillment->items->first()->cogs_total;
        $inventory->forceFill(['average_cost' => '300.0000'])->save();
        $return = $this->createReturn($owner, $order, $warehouse, 2);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(2, 0, (string) Str::uuid()), $owner);
        $this->assertSame('280.0000', $inventory->refresh()->average_cost);
        $this->assertSame($cogs, $order->fulfillment->items->first()->fresh()->cogs_total);
    }

    public function test_partial_returns_cannot_cumulatively_exceed_fulfilled_quantity(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(5, 3, '100.0000');
        $this->createReturn($owner, $order, $warehouse, 2);
        $this->createReturn($owner, $order, $warehouse, 1);
        $this->expectException(CustomerReturnException::class);
        $this->createReturn($owner, $order, $warehouse, 1);
    }

    public function test_duplicate_receive_and_inspection_are_blocked_without_inventory_change(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(4, 1, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        try {
            app(ReceiveCustomerReturn::class)->handle($return->refresh(), $owner);
            $this->fail();
        } catch (CustomerReturnException) {
        }
        $key = (string) Str::uuid();
        app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(1, 0, $key), $owner);
        try {
            app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(1, 0, $key), $owner);
            $this->fail();
        } catch (CustomerReturnException) {
        }
        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertDatabaseCount('customer_return_inspections', 1);
    }

    public function test_create_receive_and_qc_reserve_all_references_before_business_transactions(): void
    {
        [$owner, , $warehouse, , $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $probe = new class extends ReferenceSequenceService
        {
            public array $levels = [];

            public function nextCustomerReturnReference(?int $year = null): string
            {
                $this->levels['return'][] = DB::transactionLevel();

                return 'RTN-2026-990001';
            }

            public function nextStockMovementReference(): string
            {
                $this->levels['movement'][] = DB::transactionLevel();

                return 'SM-'.str_pad((string) (990000 + count($this->levels['movement'])), 6, '0', STR_PAD_LEFT);
            }

            public function nextDamagedStockReference(?int $year = null): string
            {
                $this->levels['damage'][] = DB::transactionLevel();

                return 'DMG-2026-990001';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambient = DB::transactionLevel();
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $owner);

        $this->assertSame([$ambient], $probe->levels['return']);
        $this->assertSame([$ambient, $ambient], $probe->levels['movement']);
        $this->assertSame([$ambient], $probe->levels['damage']);
    }

    public function test_failed_receive_rolls_back_status_qc_bucket_inventory_and_movements(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        $inventory->refresh();
        $before = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost', 'qc_pending_quantity', 'qc_pending_value']);
        $movementCount = StockMovement::query()->count();
        $bucketCount = DB::table('stock_movement_bucket_changes')->count();
        $this->app->instance(InventoryBalanceService::class, new class extends InventoryBalanceService
        {
            public function lockOrCreate(int $productId, int $warehouseId): ProductInventory
            {
                throw new CustomerReturnException('Simulated receive failure.');
            }
        });

        try {
            app(ReceiveCustomerReturn::class)->handle($return, $owner);
            $this->fail('Simulated Receive failure was not propagated.');
        } catch (CustomerReturnException $exception) {
            $this->assertSame('Simulated receive failure.', $exception->getMessage());
        }

        $this->assertSame(CustomerReturnStatus::Draft, $return->refresh()->status);
        $this->assertNull($return->received_at);
        $this->assertSame($before, $inventory->refresh()->only(array_keys($before)));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($bucketCount, DB::table('stock_movement_bucket_changes')->count());
        $this->assertDatabaseCount('damaged_stock_events', 0);
        $this->assertDatabaseCount('safet_claims', 0);
    }

    public function test_receiving_can_create_destination_inventory_and_cancellation_stops_after_posting(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(3, 1, '125.5000');
        $destination = Warehouse::factory()->create(['status' => true]);
        $return = $this->createReturn($owner, $order, $destination, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        $created = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $destination->id)->sole();
        $this->assertSame(1, $created->qc_pending_quantity);
        $this->assertSame(0, $created->available_quantity);
        $this->assertNull($created->average_cost);
        $this->expectException(CustomerReturnException::class);
        app(CancelCustomerReturn::class)->handle($return->refresh(), 'Too late', $owner);
    }

    public function test_direct_company_return_still_requires_a_receiving_location(): void
    {
        [$owner, , , , $order] = $this->fulfilledOrder(3, 1, '125.5000');

        $this->expectException(CustomerReturnException::class);
        app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            null,
            [['order_fulfillment_item_id' => $order->fulfillment->items->sole()->id, 'quantity' => 1, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $owner);
    }

    public function test_permission_defaults_match_phase_two_decision(): void
    {
        foreach ([EmployeeRole::Owner, EmployeeRole::Admin, EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $user = User::factory()->create();
            Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);
            $user->refresh();
            $auth = app(CustomerReturnAuthorization::class);
            $this->assertSame($role !== EmployeeRole::Staff, $auth->roleDefault($user, CustomerReturnPermission::View));
            $this->assertSame(in_array($role, [EmployeeRole::Owner, EmployeeRole::Admin], true), $auth->roleDefault($user, CustomerReturnPermission::Receive));
            $this->assertSame(in_array($role, [EmployeeRole::Owner, EmployeeRole::Admin], true), $auth->roleDefault($user, CustomerReturnPermission::Inspect));
        }
    }

    private function fulfilledOrder(int $available, int $quantity, string $cost): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => $available, 'average_cost' => $cost]);
        $data = new SaveAndReserveOrderData($warehouse->id, null, null, now()->toDateString(), $owner->employee->id, null, [new OrderItemData($product->id, $quantity, '500.00')], (string) Str::uuid());
        $order = app(SaveAsShippedOrder::class)->handle($data, $owner)->load('fulfillment.items');

        return [$owner->refresh(), $product, $warehouse, $inventory, $order];
    }

    private function createReturn(User $owner, $order, Warehouse $warehouse, int $quantity): CustomerReturn
    {
        return app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData($order->id, $warehouse->id, [['order_fulfillment_item_id' => $order->fulfillment->items->first()->id, 'quantity' => $quantity, 'return_reason' => 'other']], (string) Str::uuid()), $owner);
    }
}
