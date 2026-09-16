<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignments;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\StockTransfers\CreateStockTransfer;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Orders\WebSalesOrderData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentBatchData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Exceptions\DuplicateActiveResponsibilityException;
use App\Models\ActivityLog;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\Warehouse;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Orders\WebSalesService;
use App\Services\Responsibilities\BulkResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityAllocationService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\ResponsibilityReadService;
use App\Services\Returns\CustomerReturnReadService;
use App\Services\StockTransfers\StockTransferReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class WarehouseResponsibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_warehouse_scope_is_exact_unique_and_rejects_inactive_warehouses(): void
    {
        $f = $this->responsibilityFoundation();
        $assignment = $this->warehouseAssignment($f);

        $this->assertSame($f['inventory']->warehouse_id, $assignment->warehouseScope->warehouse_id);
        $this->assertNotNull($assignment->active_fingerprint);
        $this->assertDatabaseHas('responsibility_assignment_warehouses', [
            'assignment_id' => $assignment->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
        ]);

        $platformAssignment = $this->warehouseAssignment($f, $f['platform']->id);
        $this->assertNotSame($assignment->active_fingerprint, $platformAssignment->active_fingerprint);

        $this->expectException(DuplicateActiveResponsibilityException::class);
        $this->warehouseAssignment($f);
    }

    public function test_inactive_warehouse_is_rejected_server_side(): void
    {
        $f = $this->responsibilityFoundation();
        $f['inventory']->warehouse->forceFill(['status' => false])->save();

        try {
            $this->warehouseAssignment($f);
            $this->fail('Inactive Warehouses must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('warehouse_id', $exception->errors());
        }

        $this->assertDatabaseCount('responsibility_assignments', 0);
    }

    public function test_warehouse_multiple_platforms_expand_into_exact_assignments(): void
    {
        $f = $this->responsibilityFoundation();
        $platforms = MarketplacePlatform::factory()->count(2)->create();
        $data = new CreateResponsibilityAssignmentBatchData(
            employeeId: $f['employee']->id,
            scopeType: 'warehouse',
            scopeIds: [],
            categoryId: null,
            platformId: null,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Warehouse platform responsibility',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
            platformIds: $platforms->modelKeys(),
            warehouseId: $f['inventory']->warehouse_id,
        );

        $created = app(BulkResponsibilityAssignmentService::class)->create($data, $f['owner']);
        $retry = app(BulkResponsibilityAssignmentService::class)->create($data, $f['owner']);

        $this->assertCount(2, $created);
        $this->assertCount(2, $retry);
        $this->assertSame(2, $created->pluck('active_fingerprint')->unique()->count());
        $this->assertEqualsCanonicalizing($platforms->modelKeys(), $created->pluck('platformScope.marketplace_platform_id')->all());
        $this->assertTrue($created->every(fn (ResponsibilityAssignment $assignment): bool => $assignment->warehouseScope->warehouse_id === $f['inventory']->warehouse_id));
        $this->assertDatabaseCount('responsibility_assignments', 2);
    }

    public function test_warehouse_scope_limits_inventory_and_platform_context(): void
    {
        $f = $this->responsibilityFoundation(10);
        $otherWarehouse = Warehouse::factory()->create(['status' => true]);
        $otherProduct = Product::factory()->create();
        $otherInventory = ProductInventory::factory()->create([
            'product_id' => $otherProduct->id,
            'warehouse_id' => $otherWarehouse->id,
            'available_quantity' => 8,
        ]);
        $this->warehouseAssignment($f, $f['platform']->id);
        $scope = app(OrderResponsibilityScopeService::class);
        $inventoryScope = app(ResponsibilityProductScopeService::class);

        $this->assertTrue($scope->canAccessProduct($f['employee']->user, $f['product']->id, $f['platform']->id, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($f['employee']->user, $f['product']->id, MarketplacePlatform::factory()->create()->id, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($f['employee']->user, $otherProduct->id, $f['platform']->id, $otherWarehouse->id));
        $this->assertTrue($inventoryScope->canAccessInventory($f['employee']->user, $f['inventory']->id));
        $this->assertFalse($inventoryScope->canAccessInventory($f['employee']->user, $otherInventory->id));

        $this->reserve($f, 1, $f['employee']->user, $f['platform']->id);
        $this->assertSame(1, $f['inventory']->refresh()->reserved_quantity);
        try {
            $this->reserve($f, 1, $f['employee']->user, MarketplacePlatform::factory()->create()->id);
            $this->fail('Warehouse + Platform must reject a different sales Platform.');
        } catch (ValidationException) {
            $this->assertSame(1, $f['inventory']->refresh()->reserved_quantity);
        }

        $rows = app(ResponsibilityReadService::class)->myInventory($f['employee']->user);
        $this->assertCount(1, $rows);
        $this->assertSame($f['inventory']->id, $rows->sole()->inventory_id);
        $this->assertSame(['Amazon UAE'], $rows->sole()->platforms);
        $this->assertStringContainsString('Warehouse:', $rows->sole()->visibility_reasons[0]);
        $this->assertObjectNotHasProperty('latest_purchase_cost', $rows->sole());
    }

    public function test_warehouse_sale_uses_shared_stock_without_consuming_another_employee_allocation(): void
    {
        $f = $this->responsibilityFoundation(10);
        $warehouseUser = $this->responsibilityUser(EmployeeRole::Staff);
        $warehouseFoundation = $f;
        $warehouseFoundation['employee'] = $warehouseUser->employee;
        $this->warehouseAssignment($warehouseFoundation);
        $quantity = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 10]),
            $f['owner'],
        );

        $warehouseOrder = $this->reserve($warehouseFoundation, 5, $warehouseUser);
        app(FulfillOrder::class)->handle($warehouseOrder, (string) Str::uuid(), $f['owner']);

        $this->assertSame(5, $f['inventory']->refresh()->available_quantity);
        $this->assertSame($warehouseUser->employee->id, $warehouseOrder->refresh()->handled_by_employee_id);
        $this->assertSame(10, app(ResponsibilityAllocationService::class)->usage($quantity)['remaining']);
        $this->assertSame(5, app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole()->employee_usable);

        $quantityOrder = $this->reserve($f, 2, $f['employee']->user);
        app(FulfillOrder::class)->handle($quantityOrder, (string) Str::uuid(), $f['owner']);

        $this->assertSame(3, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(8, app(ResponsibilityAllocationService::class)->usage($quantity)['remaining']);
        $this->assertSame(3, app(ResponsibilityReadService::class)->myInventory($f['employee']->user)->sole()->employee_usable);

        try {
            $this->reserve($warehouseFoundation, 4, $warehouseUser);
            $this->fail('Competing sales must not make shared physical stock negative.');
        } catch (ValidationException) {
            $this->assertSame(3, $f['inventory']->refresh()->available_quantity);
        }
    }

    public function test_warehouse_scope_supports_web_sales_without_granting_other_warehouses(): void
    {
        $f = $this->responsibilityFoundation(5);
        $main = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $f['inventory']->forceFill(['warehouse_id' => $main->id])->save();
        $f['inventory']->unsetRelation('warehouse');
        $this->warehouseAssignment($f, warehouseId: $main->id);

        $order = app(WebSalesService::class)->createConfirmed(new WebSalesOrderData(
            customerName: 'Warehouse Customer',
            customerPhone: '+971501234567',
            channel: WebSalesChannel::WhatsApp,
            deliveryType: WebSalesDeliveryType::Courier,
            courierName: 'Courier',
            trackingNumber: null,
            items: [new OrderItemData($f['product']->id, 2, '250.00')],
            idempotencyKey: (string) Str::uuid(),
        ), $f['employee']->user);

        $this->assertSame($f['employee']->id, $order->handled_by_employee_id);
        $this->assertSame(2, $f['inventory']->refresh()->reserved_quantity);
    }

    public function test_warehouse_assignment_uses_existing_lifecycle_audit_and_bulk_deactivation(): void
    {
        $f = $this->responsibilityFoundation();
        $platforms = MarketplacePlatform::factory()->count(2)->create();
        $created = app(BulkResponsibilityAssignmentService::class)->create(new CreateResponsibilityAssignmentBatchData(
            employeeId: $f['employee']->id,
            scopeType: 'warehouse',
            scopeIds: [],
            categoryId: null,
            platformId: null,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Temporary warehouse coverage',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
            platformIds: $platforms->modelKeys(),
            warehouseId: $f['inventory']->warehouse_id,
        ), $f['owner']);

        app(DeactivateResponsibilityAssignments::class)->handle(
            $created,
            new DeactivateResponsibilityAssignmentData('Coverage ended'),
            $f['owner'],
        );

        $this->assertSame(2, ResponsibilityAssignment::query()->where('status', ResponsibilityAssignmentStatus::Inactive->value)->count());
        $this->assertTrue(ResponsibilityAssignment::query()->get()->every(fn (ResponsibilityAssignment $assignment): bool => $assignment->active_fingerprint === null && $assignment->ended_at !== null));
        $this->assertSame(2, ActivityLog::query()->where('event', 'responsibility.deactivated')->count());
        $this->assertDatabaseCount('responsibility_assignment_warehouses', 2);
    }

    public function test_warehouse_scope_limits_customer_returns_and_stock_transfers_to_the_assigned_warehouse(): void
    {
        $f = $this->responsibilityFoundation(6);
        $this->warehouseAssignment($f);
        $destination = Warehouse::factory()->create(['status' => true]);
        $otherWarehouse = Warehouse::factory()->create(['status' => true]);
        $otherProduct = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $otherProduct->id,
            'warehouse_id' => $otherWarehouse->id,
            'available_quantity' => 6,
        ]);

        $order = $this->reserve($f, 1, $f['employee']->user);
        app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $f['owner']);
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            orderId: $order->id,
            receivingWarehouseId: $f['inventory']->warehouse_id,
            items: [[
                'order_fulfillment_item_id' => $order->fulfillment->items->sole()->id,
                'quantity' => 1,
                'return_reason' => 'other',
            ]],
            idempotencyKey: (string) Str::uuid(),
        ), $f['owner']);

        $visibleTransfer = app(CreateStockTransfer::class)->handle(new CreateStockTransferData(
            $f['inventory']->warehouse_id,
            $destination->id,
            today()->toDateString(),
            [new StockTransferItemData($f['product']->id, 1)],
            (string) Str::uuid(),
        ), $f['owner']);
        app(CreateStockTransfer::class)->handle(new CreateStockTransferData(
            $otherWarehouse->id,
            $destination->id,
            today()->toDateString(),
            [new StockTransferItemData($otherProduct->id, 1)],
            (string) Str::uuid(),
        ), $f['owner']);

        $this->assertSame([$return->id], app(CustomerReturnReadService::class)->query($f['employee']->user)->pluck('id')->all());
        $this->assertSame([$visibleTransfer->id], app(StockTransferReadService::class)->query($f['employee']->user)->pluck('stock_transfers.id')->all());
    }

    private function warehouseAssignment(array $foundation, ?int $platformId = null, ?int $warehouseId = null): ResponsibilityAssignment
    {
        return app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($foundation, overrides: [
            'brandId' => null,
            'platformId' => $platformId,
            'warehouseId' => $warehouseId ?? $foundation['inventory']->warehouse_id,
        ]), $foundation['owner']);
    }

    private function reserve(array $foundation, int $quantity, $actor, ?int $platformId = null)
    {
        return app(SaveAndReserveOrder::class)->handle(new SaveAndReserveOrderData(
            warehouseId: $foundation['inventory']->warehouse_id,
            platformId: $platformId,
            externalOrderNumber: null,
            orderDate: today()->toDateString(),
            handledByEmployeeId: $foundation['employee']->id,
            notes: null,
            items: [new OrderItemData($foundation['product']->id, $quantity, '250.00')],
            idempotencyKey: (string) Str::uuid(),
        ), $actor);
    }
}
