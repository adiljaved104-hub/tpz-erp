<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Responsibilities\ChangeResponsibilityQuantity;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Orders\WebSalesOrderData;
use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\ResponsibilityAssignment;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Orders\WebSalesService;
use App\Services\Responsibilities\ResponsibilityAllocationService;
use App\Services\Responsibilities\ResponsibilityCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityAllocationEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_allocation_limits_batch_and_failed_reservation_is_atomic(): void
    {
        $f = $this->responsibilityFoundation(10);
        $assignment = $this->quantity($f, 6);

        $order = $this->reserve($f, 6);
        $this->assertSame($assignment->id, $this->attributedAssignment($order));
        $this->assertSame(0, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);

        $before = $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity']);
        try {
            $this->reserve($f, 1);
            $this->fail('A consumed allocation must reject another reservation.');
        } catch (ValidationException) {
            $this->assertSame($before, $f['inventory']->refresh()->only(array_keys($before)));
        }
        $this->assertSame(1, Order::query()->count());
    }

    public function test_assigned_quantity_is_a_total_cap_across_same_product_order_lines(): void
    {
        $f = $this->responsibilityFoundation(10);
        $this->quantity($f, 6);

        try {
            $this->reserveItems($f, [
                new OrderItemData($f['product']->id, 4, '250.00'),
                new OrderItemData($f['product']->id, 3, '250.00'),
            ]);
            $this->fail('A batch total of seven must exceed an allocation of six.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('remaining allocation of 6', $exception->getMessage());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $f['inventory']->refresh()->reserved_quantity);
        $this->assertDatabaseCount('responsibility_inventory_consumptions', 0);
    }

    public function test_central_sellable_stock_can_be_tighter_than_employee_allocation(): void
    {
        $f = $this->responsibilityFoundation(3);
        $this->quantity($f, 3);
        $other = $this->responsibilityUser(EmployeeRole::Staff);

        try {
            $this->quantity($f, 1, $other->employee->id);
            $this->fail('Aggregate outstanding allocations must not exceed central Sellable inventory.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('sellable units exist', strtolower($exception->getMessage()));
        }

        $this->assertSame(1, ResponsibilityAssignment::query()->active()->whereHas('quantityScope')->count());
        $this->assertSame(3, $f['inventory']->refresh()->available_quantity);
    }

    public function test_release_restores_and_fulfilment_continues_to_consume_allocation(): void
    {
        $f = $this->responsibilityFoundation(10);
        $assignment = $this->quantity($f, 6);
        $order = $this->reserve($f, 2);
        $this->assertSame(4, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);

        app(CancelOrder::class)->handle($order, new CancelOrderData('Customer cancelled', (string) Str::uuid()), $f['owner']);
        $this->assertSame(6, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);

        $second = $this->reserve($f, 2);
        app(FulfillOrder::class)->handle($second, (string) Str::uuid(), $f['owner']);
        $usage = app(ResponsibilityAllocationService::class)->usage($assignment);
        $this->assertSame(2, $usage['fulfilled']);
        $this->assertSame(4, $usage['remaining']);
        $capacity = app(ResponsibilityCapacityService::class)->summary($f['inventory']->id);
        $this->assertSame(4, $capacity['outstanding']);
        $this->assertSame(4, $capacity['remaining']);
    }

    public function test_broader_scopes_cannot_bypass_exact_inventory_allocation(): void
    {
        $f = $this->responsibilityFoundation(10);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'productId' => $f['product']->id,
        ]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'categoryId' => $f['product']->category_id,
        ]), $f['owner']);
        $this->quantity($f, 3);

        $this->expectException(ValidationException::class);
        $this->reserve($f, 4);
    }

    public function test_no_quantity_assignment_keeps_shared_scope_behavior(): void
    {
        $f = $this->responsibilityFoundation(10);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);

        $order = $this->reserve($f, 4);

        $this->assertSame(4, $order->items()->sole()->ordered_quantity);
        $this->assertDatabaseCount('responsibility_inventory_consumptions', 0);
    }

    public function test_two_employees_have_independent_caps_against_one_central_balance(): void
    {
        $f = $this->responsibilityFoundation(10);
        $this->quantity($f, 6);
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $this->quantity($f, 4, $second->employee->id);

        $this->reserve($f, 6);
        $secondFoundation = $f;
        $secondFoundation['employee'] = $second->employee;
        $this->reserve($secondFoundation, 4);

        $this->assertSame(10, $f['inventory']->refresh()->reserved_quantity);
        $this->assertSame(2, ResponsibilityAssignment::query()->active()->whereHas('quantityScope')->count());
    }

    public function test_idempotent_order_retry_does_not_consume_allocation_twice(): void
    {
        $f = $this->responsibilityFoundation(10);
        $assignment = $this->quantity($f, 6);
        $data = $this->orderData($f, [new OrderItemData($f['product']->id, 2, '250.00')]);

        $first = app(SaveAndReserveOrder::class)->handle($data, $f['employee']->user);
        $retry = app(SaveAndReserveOrder::class)->handle($data, $f['employee']->user);

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('responsibility_inventory_consumptions', 1);
        $this->assertSame(4, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);
    }

    public function test_web_sales_uses_the_same_quantity_cap_and_attribution(): void
    {
        $f = $this->responsibilityFoundation(10);
        $this->makeMainWarehouse($f);
        $assignment = $this->quantity($f, 3);

        try {
            app(WebSalesService::class)->createConfirmed($this->webSaleData($f, 4), $f['employee']->user);
            $this->fail('Web Sales must not bypass the exact quantity cap.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
        }

        $order = app(WebSalesService::class)->createConfirmed($this->webSaleData($f, 3), $f['employee']->user);
        $this->assertSame($assignment->id, $this->attributedAssignment($order));
        $this->assertSame(0, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);
    }

    public function test_walk_in_web_sale_uses_the_same_cap_without_a_reservation(): void
    {
        $f = $this->responsibilityFoundation(10);
        $this->makeMainWarehouse($f);
        app(EmployeePermissionOverrideService::class)->change(
            $f['employee'],
            OrderPermission::Fulfill->value,
            EmployeePermissionEffect::Allow,
            'Direct sale allocation regression',
            $f['owner'],
        );
        $assignment = $this->quantity($f, 3);
        $data = new WebSalesOrderData(
            customerName: 'Walk-in Customer',
            customerPhone: '+971501234567',
            channel: WebSalesChannel::WalkIn,
            deliveryType: WebSalesDeliveryType::ShopPickup,
            courierName: null,
            trackingNumber: null,
            items: [new OrderItemData($f['product']->id, 2, '250.00')],
            idempotencyKey: (string) Str::uuid(),
        );

        $order = app(WebSalesService::class)->completeSale($data, $f['employee']->user);
        $retry = app(WebSalesService::class)->completeSale($data, $f['employee']->user);

        $this->assertSame($order->id, $retry->id);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('responsibility_inventory_consumptions', 1);
        $this->assertSame(1, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);
    }

    public function test_competing_reservations_cannot_cumulatively_exceed_allocation(): void
    {
        $f = $this->responsibilityFoundation(10);
        $assignment = $this->quantity($f, 3);
        $this->reserve($f, 2);

        try {
            $this->reserve($f, 2);
            $this->fail('A competing request must observe already-consumed allocation.');
        } catch (ValidationException) {
            $this->assertSame(1, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);
        }

        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('responsibility_inventory_consumptions', 1);
        $this->assertSame(2, $f['inventory']->refresh()->reserved_quantity);
    }

    public function test_active_consumption_blocks_transfer_change_and_deactivation(): void
    {
        $f = $this->responsibilityFoundation(10);
        $assignment = $this->quantity($f, 6);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $this->reserve($f, 2);

        foreach ([
            fn () => app(ChangeResponsibilityQuantity::class)->handle($assignment, new ChangeResponsibilityQuantityData(7, 'Increase', (string) Str::uuid()), $f['owner']),
            fn () => app(TransferResponsibilityAssignment::class)->handle($assignment, new TransferResponsibilityAssignmentData($destination->employee->id, 'Transfer', (string) Str::uuid()), $f['owner']),
            fn () => app(DeactivateResponsibilityAssignment::class)->handle($assignment, new DeactivateResponsibilityAssignmentData('Deactivate'), $f['owner']),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Active allocation consumption must prevent assignment history changes.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('active', $assignment->refresh()->status->value);
        $this->assertDatabaseCount('responsibility_assignments', 1);
    }

    public function test_fulfilled_consumption_remains_attributed_and_sellable_return_restores_allocation(): void
    {
        $f = $this->responsibilityFoundation(10);
        $assignment = $this->quantity($f, 6);
        $order = $this->reserve($f, 2);
        $order = app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $f['owner'])->load('fulfillment.items');
        $this->assertSame(4, app(ResponsibilityAllocationService::class)->usage($assignment)['remaining']);

        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            $f['inventory']->warehouse_id,
            [[
                'order_fulfillment_item_id' => $order->fulfillment->items->sole()->id,
                'quantity' => 1,
                'return_reason' => 'other',
            ]],
            (string) Str::uuid(),
        ), $f['owner']);
        app(ReceiveCustomerReturn::class)->handle($return, $f['owner']);
        app(InspectCustomerReturnItem::class)->handle(
            $return->items()->sole(),
            new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()),
            $f['owner'],
        );

        $usage = app(ResponsibilityAllocationService::class)->usage($assignment);
        $this->assertSame(2, $usage['fulfilled']);
        $this->assertSame(1, $usage['restored']);
        $this->assertSame(5, $usage['remaining']);
        $this->assertDatabaseCount('responsibility_inventory_consumptions', 1);

        app(DeactivateResponsibilityAssignment::class)->handle($assignment, new DeactivateResponsibilityAssignmentData('Fulfilled allocation ended'), $f['owner']);
        $this->assertSame($assignment->id, (int) DB::table('responsibility_inventory_consumptions')->value('responsibility_assignment_id'));
    }

    private function quantity(array $f, int $quantity, ?int $employeeId = null): ResponsibilityAssignment
    {
        return app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, [
            'employeeId' => $employeeId ?? $f['employee']->id,
            'assignedQuantity' => $quantity,
        ]), $f['owner']);
    }

    private function reserve(array $f, int $quantity): Order
    {
        return $this->reserveItems($f, [new OrderItemData($f['product']->id, $quantity, '250.00')]);
    }

    /** @param array<int, OrderItemData> $items */
    private function reserveItems(array $f, array $items): Order
    {
        return app(SaveAndReserveOrder::class)->handle($this->orderData($f, $items), $f['employee']->user);
    }

    /** @param array<int, OrderItemData> $items */
    private function orderData(array $f, array $items): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData(
            warehouseId: $f['inventory']->warehouse_id,
            platformId: null,
            externalOrderNumber: null,
            orderDate: today()->toDateString(),
            handledByEmployeeId: $f['employee']->id,
            notes: null,
            items: $items,
            idempotencyKey: (string) Str::uuid(),
        );
    }

    private function webSaleData(array $f, int $quantity): WebSalesOrderData
    {
        return new WebSalesOrderData(
            customerName: 'Allocation Customer',
            customerPhone: '+971501234567',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'Courier',
            trackingNumber: null,
            items: [new OrderItemData($f['product']->id, $quantity, '250.00')],
            idempotencyKey: (string) Str::uuid(),
        );
    }

    private function makeMainWarehouse(array $f): void
    {
        $main = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $main->forceFill(['is_default' => true, 'status' => true])->save();
        $f['inventory']->forceFill(['warehouse_id' => $main->id])->save();
        $f['inventory']->unsetRelation('warehouse');
    }

    private function attributedAssignment(Order $order): int
    {
        $reservation = InventoryReservation::query()->where('order_item_id', $order->items()->sole()->id)->sole();

        return (int) DB::table('responsibility_inventory_consumptions')->where('inventory_reservation_id', $reservation->id)->value('responsibility_assignment_id');
    }
}
