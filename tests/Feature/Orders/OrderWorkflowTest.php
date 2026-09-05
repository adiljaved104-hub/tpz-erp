<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Orders\SaveAsShippedOrder;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryReservationStatus;
use App\Enums\OrderStatus;
use App\Exceptions\ImmutableOrderException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\InventoryReservation;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_and_reserve_is_atomic_and_does_not_change_physical_available_stock(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(10, 2);

        $order = app(SaveAndReserveOrder::class)->handle($this->data($warehouse, $product, $owner, 3, '150.00'), $owner);

        $this->assertSame(OrderStatus::Reserved, $order->status);
        $this->assertMatchesRegularExpression('/^SO-\d{4}-\d{6}$/', $order->reference);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderItem::query()->count());
        $this->assertSame(1, InventoryReservation::query()->whereNotNull('order_item_id')->count());
        $this->assertSame(10, $inventory->refresh()->available_quantity);
        $this->assertSame(5, $inventory->reserved_quantity);
        $this->assertSame(5, $inventory->sellableQuantity());
        $movement = StockMovement::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $movement->available_delta);
        $this->assertSame(3, $movement->reserved_delta);
        $this->assertSame(2, OrderStatusEvent::query()->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'order.created', 'subject_id' => $order->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'order.reserved', 'subject_id' => $order->id]);
    }

    public function test_cancellation_releases_order_reservations_without_changing_available_stock(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(10, 1);
        $order = app(SaveAndReserveOrder::class)->handle($this->data($warehouse, $product, $owner, 2, '100.00'), $owner);

        $cancellationKey = (string) Str::uuid();
        app(CancelOrder::class)->handle($order, new CancelOrderData('Customer cancelled', $cancellationKey), $owner);
        app(CancelOrder::class)->handle($order->refresh(), new CancelOrderData('Customer cancelled', $cancellationKey), $owner);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(10, $inventory->refresh()->available_quantity);
        $this->assertSame(1, $inventory->reserved_quantity);
        $this->assertSame(InventoryReservationStatus::Released, $order->items()->firstOrFail()->reservation->status);
        $this->assertSame(-2, StockMovement::query()->latest('id')->firstOrFail()->reserved_delta);
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_any_failing_line_rolls_back_the_entire_order_and_all_reservations(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(2, 0);
        $other = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $other->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 0,
        ]);
        $data = new SaveAndReserveOrderData(
            $warehouse->id,
            null,
            null,
            now()->toDateString(),
            $owner->employee->id,
            null,
            [new OrderItemData($product->id, 1, '100.00'), new OrderItemData($other->id, 1, '200.00')],
            (string) Str::uuid(),
        );

        try {
            app(SaveAndReserveOrder::class)->handle($data, $owner);
            $this->fail('The Order should have failed atomically.');
        } catch (ValidationException) {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, InventoryReservation::query()->whereNotNull('order_item_id')->count());
            $this->assertSame(0, $inventory->refresh()->reserved_quantity);
        }
    }

    public function test_external_identity_is_unique_per_platform_and_manual_number_is_optional(): void
    {
        [$owner, $product, $warehouse] = $this->foundation(10, 0);
        $data = $this->data($warehouse, $product, $owner, 1, '100.00');
        $first = app(SaveAndReserveOrder::class)->handle($data, $owner);
        $retry = app(SaveAndReserveOrder::class)->handle($data, $owner);

        $this->assertSame($first->id, $retry->id);
        $this->assertNull($first->external_order_number);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_external_order_identity_is_case_and_whitespace_insensitive_per_platform(): void
    {
        [$owner, $product, $warehouse] = $this->foundation(10, 0);
        $platform = MarketplacePlatform::factory()->create();
        $make = fn (string $number): SaveAndReserveOrderData => new SaveAndReserveOrderData(
            $warehouse->id,
            $platform->id,
            $number,
            now()->toDateString(),
            $owner->employee->id,
            null,
            [new OrderItemData($product->id, 1, '100.00')],
            (string) Str::uuid(),
        );
        app(SaveAndReserveOrder::class)->handle($make('  AMZ-100  '), $owner);

        $this->expectException(ValidationException::class);
        app(SaveAndReserveOrder::class)->handle($make('amz-100'), $owner);
    }

    public function test_activity_logs_do_not_contain_selling_prices_or_totals(): void
    {
        [$owner, $product, $warehouse] = $this->foundation(10, 0);
        app(SaveAndReserveOrder::class)->handle($this->data($warehouse, $product, $owner, 1, '987.65'), $owner);

        $payload = ActivityLog::query()->whereIn('event', ['order.created', 'order.reserved'])->pluck('properties')->toJson();
        $this->assertStringNotContainsString('987.65', $payload);
        $this->assertStringNotContainsString('selling_price', strtolower($payload));
        $this->assertStringNotContainsString('grand_total', strtolower($payload));
    }

    public function test_direct_save_as_shipped_posts_outbound_stock_and_immutable_cogs_without_a_reservation(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(10, 0);
        $inventory->forceFill(['average_cost' => '125.1234'])->save();
        $data = $this->data($warehouse, $product, $owner, 2, '300.00');

        $order = app(SaveAsShippedOrder::class)->handle($data, $owner);

        $this->assertSame(OrderStatus::Fulfilled, $order->status);
        $this->assertMatchesRegularExpression('/^SOF-\d{4}-\d{6}$/', $order->fulfillment->reference);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertSame(8, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(8, $inventory->sellableQuantity());
        $this->assertSame(8, $inventory->totalOnHand());
        $this->assertSame('125.1234', $inventory->average_cost);
        $fulfillmentItem = OrderFulfillmentItem::query()->sole();
        $this->assertSame('125.1234', $fulfillmentItem->inventory_unit_cost);
        $this->assertSame('250.2468', $fulfillmentItem->cogs_total);
        $movement = StockMovement::query()->sole();
        $this->assertSame('order_fulfillment', $movement->movement_type->value);
        $this->assertSame(-2, $movement->available_delta);
        $this->assertSame(0, $movement->reserved_delta);
        $this->assertSame($fulfillmentItem->id, $movement->source_id);

        try {
            $order->fulfillment->update(['reference' => 'CHANGED']);
            $this->fail('The Fulfilment header must be immutable.');
        } catch (ImmutableOrderException) {
            $this->assertNotSame('CHANGED', $order->fulfillment->fresh()->reference);
        }

        $this->expectException(ImmutableOrderException::class);
        $fulfillmentItem->update(['quantity' => 1]);
    }

    public function test_direct_shipping_is_idempotent_and_never_deducts_twice(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(10, 0);
        $inventory->forceFill(['average_cost' => '100.0000'])->save();
        $data = $this->data($warehouse, $product, $owner, 2, '300.00');

        $first = app(SaveAsShippedOrder::class)->handle($data, $owner);
        $retry = app(SaveAsShippedOrder::class)->handle($data, $owner);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(8, $inventory->refresh()->available_quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_fulfillments', 1);
        $this->assertDatabaseCount('order_fulfillment_items', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_direct_shipping_rejects_insufficient_stock_and_null_average_cost_atomically(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(1, 0);
        $inventory->forceFill(['average_cost' => '100.0000'])->save();

        try {
            app(SaveAsShippedOrder::class)->handle($this->data($warehouse, $product, $owner, 2, '300.00'), $owner);
            $this->fail('Insufficient stock should reject direct shipping.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertSame(1, $inventory->refresh()->available_quantity);
        }

        $inventory->forceFill(['available_quantity' => 2, 'average_cost' => null])->save();

        try {
            app(SaveAsShippedOrder::class)->handle($this->data($warehouse, $product, $owner, 1, '300.00'), $owner);
            $this->fail('A null average cost should reject direct shipping.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_fulfillments', 0);
            $this->assertDatabaseCount('stock_movements', 0);
            $this->assertSame(2, $inventory->refresh()->available_quantity);
        }
    }

    public function test_reserved_order_fulfillment_reduces_available_and_reserved_together_without_second_sellable_reduction(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(10, 0);
        $inventory->forceFill(['average_cost' => '75.5000'])->save();
        $order = app(SaveAndReserveOrder::class)->handle($this->data($warehouse, $product, $owner, 2, '300.00'), $owner);
        $this->assertSame(8, $inventory->refresh()->sellableQuantity());
        $key = (string) Str::uuid();

        $fulfilled = app(FulfillOrder::class)->handle($order, $key, $owner);
        $retry = app(FulfillOrder::class)->handle($order->refresh(), $key, $owner);

        $this->assertSame($fulfilled->id, $retry->id);
        $this->assertSame(OrderStatus::Fulfilled, $fulfilled->status);
        $this->assertSame(8, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(8, $inventory->sellableQuantity());
        $this->assertSame(InventoryReservationStatus::Fulfilled, $order->items()->sole()->reservation->status);
        $this->assertSame('75.5000', $order->fulfillment->items()->sole()->inventory_unit_cost);
        $this->assertSame('151.0000', $order->fulfillment->items()->sole()->cogs_total);
        $this->assertSame(-2, StockMovement::query()->latest('id')->firstOrFail()->available_delta);
        $this->assertSame(-2, StockMovement::query()->latest('id')->firstOrFail()->reserved_delta);
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    public function test_fulfilled_order_cannot_be_cancelled_as_an_inventory_reversal(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(5, 0);
        $inventory->forceFill(['average_cost' => '100.0000'])->save();
        $order = app(SaveAsShippedOrder::class)->handle($this->data($warehouse, $product, $owner, 1, '300.00'), $owner);

        $this->expectException(InvalidOrderTransitionException::class);
        app(CancelOrder::class)->handle($order, new CancelOrderData('Cannot cancel shipped stock', (string) Str::uuid()), $owner);
    }

    /** @return array{User, Product, Warehouse, ProductInventory} */
    private function foundation(int $available, int $reserved): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => $available,
            'reserved_quantity' => $reserved,
        ]);

        return [$owner->refresh(), $product, $warehouse, $inventory];
    }

    private function data(Warehouse $warehouse, Product $product, User $owner, int $quantity, string $price): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData(
            $warehouse->id,
            null,
            null,
            now()->toDateString(),
            $owner->employee->id,
            'Test Order',
            [new OrderItemData($product->id, $quantity, $price)],
            (string) Str::uuid(),
        );
    }
}
