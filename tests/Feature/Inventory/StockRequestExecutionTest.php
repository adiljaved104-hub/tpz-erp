<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\EmployeeRole;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestSourceStatus;
use App\Enums\StockRequestStatus;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationReservationLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\StockRequestApprovalService;
use App\Services\Inventory\StockRequestExecutionService;
use App\Services\Inventory\StockRequestService;
use App\Services\Orders\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockRequestExecutionTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_permanent_transfer_moves_exact_approved_sources_without_changing_physical_or_quantity_responsibility(): void
    {
        $f = $this->responsibilityFoundation(10);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'Permanent Transfer Team', 'status' => true]);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($holder->employee->id), 2, $f['owner'], 'F3 employee source');
        $allocations->reconcile($f['inventory'], $allocations->teamAccount($team->id), 2, $f['owner'], 'F3 team source');
        $request = $this->request($f['owner'], StockRequestPurpose::PermanentTransfer, $f['inventory']->id, 6);
        $this->approveAll($request, $f['owner']);
        $physical = $f['inventory']->only(['available_quantity', 'reserved_quantity', 'warehouse_id']);
        $responsibilities = $f['employee']->responsibilityAssignments()->count();
        $key = (string) Str::uuid();

        $first = app(StockRequestExecutionService::class)->execute($request, $key, $f['owner']);
        $retry = app(StockRequestExecutionService::class)->execute($request->refresh(), $key, $f['owner']);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(StockRequestStatus::Completed, $request->refresh()->status);
        $this->assertSame(6, InventoryAllocationBalance::query()->where('account_id', $allocations->employeeAccount($f['owner']->employee->id)->id)->where('product_inventory_id', $f['inventory']->id)->value('allocated_quantity'));
        $this->assertSame($physical, $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'warehouse_id']));
        $this->assertSame($responsibilities, $f['employee']->responsibilityAssignments()->count());
        $this->assertDatabaseCount('stock_request_executions', 1);
        $this->assertDatabaseCount('stock_request_execution_lines', 3);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'stock_request_transfer', 'source_id' => $request->id]);
    }

    public function test_insufficient_current_source_rolls_back_every_transfer_line(): void
    {
        $f = $this->responsibilityFoundation(8);
        app(InventoryAllocationService::class)->ensureShadowCoverage($f['inventory'], $f['owner']);
        $request = $this->request($f['owner'], StockRequestPurpose::PermanentTransfer, $f['inventory']->id, 5);
        $this->approveAll($request, $f['owner']);
        $source = $request->sourceLines()->firstOrFail();
        InventoryAllocationBalance::query()->where('account_id', $source->inventory_allocation_account_id)->where('product_inventory_id', $f['inventory']->id)->update(['allocated_quantity' => 2]);

        try {
            app(StockRequestExecutionService::class)->execute($request, (string) Str::uuid(), $f['owner']);
            $this->fail('Stale source availability must block execution.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('now has only 2', $exception->errors()['execution'][0]);
        }

        $this->assertSame(StockRequestStatus::Approved, $request->refresh()->status);
        $this->assertDatabaseCount('stock_request_executions', 0);
        $this->assertDatabaseCount('stock_request_execution_lines', 0);
    }

    public function test_for_order_reserves_exact_approved_source_and_cancellation_restores_it(): void
    {
        $f = $this->responsibilityFoundation(6);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'Laptop Team', 'status' => true]);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($holder->employee->id), 1, $f['owner'], 'Employee source');
        $allocations->reconcile($f['inventory'], $allocations->teamAccount($team->id), 1, $f['owner'], 'Team source');
        $order = $this->order($f['owner'], $f['inventory']->warehouse_id, $f['product']->id, 3);
        $request = $this->request($f['owner'], StockRequestPurpose::ForOrder, $f['inventory']->id, 3, $order->id);
        $this->approveAll($request, $f['owner']);

        $execution = app(StockRequestExecutionService::class)->execute($request, (string) Str::uuid(), $f['owner']);
        $reservation = $order->items()->firstOrFail()->reservation()->firstOrFail();
        $sources = $request->sourceLines()->get();
        $this->assertSame(OrderStatus::Reserved, $order->refresh()->status);
        $this->assertSame(['employee', 'team', 'system'], $sources->pluck('source_type')->all());
        $this->assertSame(3, InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)->where('status', 'reserved')->count());
        $this->assertSame(3, $execution->lines()->where('inventory_reservation_id', $reservation->id)->sum('quantity'));

        app(OrderService::class)->cancel($order, new CancelOrderData('Request no longer needed', (string) Str::uuid()), $f['owner']);
        $this->assertSame(3, InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)->where('status', 'released')->count());
        $this->assertSame(0, InventoryAllocationBalance::query()->whereIn('account_id', $sources->pluck('inventory_allocation_account_id'))->where('product_inventory_id', $f['inventory']->id)->sum('reserved_quantity'));
    }

    public function test_fulfilment_consumes_exact_attribution_once(): void
    {
        $f = $this->responsibilityFoundation(5);
        app(InventoryAllocationService::class)->ensureShadowCoverage($f['inventory'], $f['owner']);
        $order = $this->order($f['owner'], $f['inventory']->warehouse_id, $f['product']->id, 2);
        $request = $this->request($f['owner'], StockRequestPurpose::ForOrder, $f['inventory']->id, 2, $order->id);
        $this->approveAll($request, $f['owner']);
        app(StockRequestExecutionService::class)->execute($request, (string) Str::uuid(), $f['owner']);
        $reservation = $order->items()->firstOrFail()->reservation()->firstOrFail();
        $key = (string) Str::uuid();

        app(OrderService::class)->fulfill($order->refresh(), $key, $f['owner']);
        app(OrderService::class)->fulfill($order->refresh(), $key, $f['owner']);

        $this->assertSame('fulfilled', InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)->value('status'));
        $this->assertSame(3, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(0, $f['inventory']->reserved_quantity);
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    public function test_requester_has_no_implicit_execution_permission_and_incomplete_request_is_blocked(): void
    {
        $f = $this->responsibilityFoundation(4);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        app(InventoryAllocationService::class)->ensureShadowCoverage($f['inventory'], $f['owner']);
        $request = $this->request($f['employee']->user, StockRequestPurpose::PermanentTransfer, $f['inventory']->id, 2);

        $this->expectException(AuthorizationException::class);
        app(StockRequestExecutionService::class)->execute($request, (string) Str::uuid(), $f['employee']->user);
    }

    public function test_workflow_notifications_route_to_the_request_and_remain_idempotent(): void
    {
        $f = $this->responsibilityFoundation(4);
        app(InventoryAllocationService::class)->ensureShadowCoverage($f['inventory'], $f['owner']);
        $request = $this->request($f['owner'], StockRequestPurpose::PermanentTransfer, $f['inventory']->id, 2);
        $line = $request->sourceLines()->sole();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $f['owner']->id, 'type' => 'stock_request.created']);

        $key = (string) Str::uuid();
        $approvals = app(StockRequestApprovalService::class);
        $approvals->decide($line, StockRequestSourceStatus::Approved, null, $key, $f['owner']);
        $approvals->decide($line->refresh(), StockRequestSourceStatus::Approved, null, $key, $f['owner']);
        $this->assertSame(1, $f['owner']->notifications()->where('type', 'stock_request.source_approved')->count());
        $this->assertSame(1, $f['owner']->notifications()->where('type', 'stock_request.approved')->count());

        app(StockRequestExecutionService::class)->execute($request->refresh(), (string) Str::uuid(), $f['owner']);
        $notification = $f['owner']->notifications()->where('type', 'stock_request.completed')->sole();
        $this->assertSame('stock_request', $notification->data['target_type']);
        $this->assertSame($request->id, $notification->data['target_id']);
    }

    private function request(User $actor, StockRequestPurpose $purpose, int $inventoryId, int $quantity, ?int $orderId = null): StockRequest
    {
        return app(StockRequestService::class)->create(new CreateStockRequestData(
            $purpose, $orderId, [new StockRequestItemData($inventoryId, $quantity)], 'Operational stock request', (string) Str::uuid(),
        ), $actor);
    }

    private function approveAll(StockRequest $request, User $actor): void
    {
        foreach ($request->sourceLines()->get() as $line) {
            app(StockRequestApprovalService::class)->decide($line, StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $actor);
        }
        $this->assertSame(StockRequestStatus::Approved, $request->refresh()->status);
    }

    private function order(User $owner, int $warehouseId, int $productId, int $quantity): Order
    {
        $order = Order::query()->create([
            'reference' => 'SO-'.now()->format('Y').'-'.random_int(100000, 999999), 'source' => OrderSource::Manual,
            'status' => OrderStatus::Draft, 'warehouse_id' => $warehouseId, 'order_date' => today(),
            'subtotal' => 300, 'discount_total' => 0, 'vat_total' => 0, 'grand_total' => 300,
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        $product = $this->product($productId);
        OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'sku' => $product->sku, 'ordered_quantity' => $quantity, 'selling_price' => 100,
            'discount_total' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'line_total' => 100 * $quantity,
        ]);

        return $order;
    }

    private function product(int $id): Product
    {
        return Product::query()->findOrFail($id);
    }
}
