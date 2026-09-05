<?php

namespace Tests\Feature\StockTransfers;

use App\Actions\StockTransfers\CancelStockTransfer;
use App\Actions\StockTransfers\CreateStockTransfer;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Actions\StockTransfers\ReceiveStockTransfer;
use App\Actions\StockTransfers\ReturnStockTransferToSource;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockMovementType;
use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Inventory\InventoryLocationOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_dispatch_and_receive_are_atomic_and_preserve_company_owned_quantity(): void
    {
        [$owner, $source, $destination, $product, $sourceInventory] = $this->foundation(10, 2, '100.0000');
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $destination->id, 'available_quantity' => 5, 'reserved_quantity' => 0, 'damaged_quantity' => 0, 'average_cost' => '200.0000']);
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 4)]);
        $beforeOwned = ProductInventory::query()->sum('available_quantity') + ProductInventory::query()->sum('damaged_quantity');

        $transfer = app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        $this->assertSame(StockTransferStatus::Dispatched, $transfer->status);
        $this->assertSame(6, $sourceInventory->refresh()->available_quantity);
        $this->assertSame(2, $sourceInventory->reserved_quantity);
        $this->assertSame('100.0000', $sourceInventory->average_cost);
        $this->assertSame(4, $transfer->items->first()->inTransitQuantity());
        $this->assertSame($beforeOwned, ProductInventory::query()->sum('available_quantity') + ProductInventory::query()->sum('damaged_quantity') + $transfer->items->sum->inTransitQuantity());

        $transfer = app(ReceiveStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        $destinationInventory = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $destination->id)->firstOrFail();
        $this->assertSame(StockTransferStatus::Received, $transfer->status);
        $this->assertSame(9, $destinationInventory->available_quantity);
        $this->assertSame('155.5556', $destinationInventory->average_cost);
        $this->assertSame(0, $transfer->items->first()->inTransitQuantity());
        $this->assertSame($beforeOwned, ProductInventory::query()->sum('available_quantity') + ProductInventory::query()->sum('damaged_quantity'));
        $this->assertSame([StockMovementType::TransferDispatch, StockMovementType::TransferReceipt], StockMovement::query()->orderBy('id')->pluck('movement_type')->all());
    }

    public function test_dispatch_rejects_reserved_stock_and_rolls_back_every_line(): void
    {
        [$owner, $source, $destination, $first, $firstInventory] = $this->foundation(10, 7, '100.0000');
        $second = Product::factory()->create();
        ProductInventory::factory()->create(['product_id' => $second->id, 'warehouse_id' => $source->id, 'available_quantity' => 1, 'reserved_quantity' => 0, 'average_cost' => '50.0000']);
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($first->id, 4), new StockTransferItemData($second->id, 2)]);

        try {
            app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
            $this->fail('Dispatch must reject insufficient Sellable inventory.');
        } catch (InsufficientInventoryException) {
        }

        $this->assertSame(10, $firstInventory->refresh()->available_quantity);
        $this->assertSame(StockTransferStatus::Draft, $transfer->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame([0, 0], $transfer->items()->orderBy('id')->pluck('dispatched_quantity')->all());
    }

    public function test_dispatch_allows_quantity_below_sellable_stock_when_inventory_has_reservations(): void
    {
        [$owner, $source, $destination, $product, $sourceInventory] = $this->foundation(12, 7, '1217.1428');
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 3)]);

        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);

        $sourceInventory->refresh();
        $this->assertSame(9, $sourceInventory->available_quantity);
        $this->assertSame(7, $sourceInventory->reserved_quantity);
        $this->assertSame(2, $sourceInventory->sellableQuantity());
        $this->assertSame('1217.1428', $sourceInventory->average_cost);
    }

    public function test_dispatch_allows_quantity_equal_to_sellable_stock_when_inventory_has_reservations(): void
    {
        [$owner, $source, $destination, $product, $sourceInventory] = $this->foundation(12, 7, '1217.1428');
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 5)]);

        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);

        $sourceInventory->refresh();
        $this->assertSame(7, $sourceInventory->available_quantity);
        $this->assertSame(7, $sourceInventory->reserved_quantity);
        $this->assertSame(0, $sourceInventory->sellableQuantity());
        $this->assertSame('1217.1428', $sourceInventory->average_cost);
    }

    public function test_dispatch_rejects_quantity_above_sellable_stock_when_inventory_has_reservations(): void
    {
        [$owner, $source, $destination, $product, $sourceInventory] = $this->foundation(12, 7, '1217.1428');
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 6)]);

        $this->expectException(InsufficientInventoryException::class);

        try {
            app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        } finally {
            $sourceInventory->refresh();
            $this->assertSame(12, $sourceInventory->available_quantity);
            $this->assertSame(7, $sourceInventory->reserved_quantity);
            $this->assertSame(5, $sourceInventory->sellableQuantity());
            $this->assertSame('1217.1428', $sourceInventory->average_cost);
        }
    }

    public function test_receive_creates_missing_destination_inventory_and_double_actions_are_blocked(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation(5, 0, '75.2500');
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 2)]);
        $dispatchKey = (string) Str::uuid();
        app(DispatchStockTransfer::class)->handle($transfer, $dispatchKey, $owner);
        $this->assertSame(1, StockMovement::query()->count());
        app(DispatchStockTransfer::class)->handle($transfer->refresh(), $dispatchKey, $owner);
        $this->assertSame(1, StockMovement::query()->count());

        $receiptKey = (string) Str::uuid();
        app(ReceiveStockTransfer::class)->handle($transfer->refresh(), $receiptKey, $owner);
        $inventory = ProductInventory::query()->where('warehouse_id', $destination->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(2, $inventory->available_quantity);
        $this->assertSame('75.2500', $inventory->average_cost);
        app(ReceiveStockTransfer::class)->handle($transfer->refresh(), $receiptKey, $owner);
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_dispatched_transfer_can_only_be_returned_with_a_reason(): void
    {
        [$owner, $source, $destination, $product, $inventory] = $this->foundation(8, 0, '125.0000');
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 3)]);
        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        $transfer = app(ReturnStockTransferToSource::class)->handle($transfer->refresh(), 'Courier returned shipment', (string) Str::uuid(), $owner);

        $this->assertSame(StockTransferStatus::Returned, $transfer->status);
        $this->assertSame(8, $inventory->refresh()->available_quantity);
        $this->assertSame(3, $transfer->items->first()->returned_quantity);
        $this->assertSame(StockMovementType::TransferReturnedToSource, StockMovement::query()->latest('id')->value('movement_type'));
    }

    public function test_cancel_is_draft_only_and_changes_no_inventory(): void
    {
        [$owner, $source, $destination, $product, $inventory] = $this->foundation();
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 1)]);
        $transfer = app(CancelStockTransfer::class)->handle($transfer, 'No longer required', (string) Str::uuid(), $owner);
        $this->assertSame(StockTransferStatus::Cancelled, $transfer->status);
        $this->assertSame(10, $inventory->refresh()->available_quantity);
        $this->expectException(InvalidStockTransferTransitionException::class);
        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
    }

    public function test_permission_defaults_and_financial_activity_safety(): void
    {
        $authorization = app(StockTransferAuthorization::class);
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);
        $this->assertTrue($authorization->allows($owner, StockTransferPermission::ViewCost));
        $this->assertTrue($authorization->allows($admin, StockTransferPermission::Dispatch));
        $this->assertFalse($authorization->allows($admin, StockTransferPermission::ViewCost));
        $this->assertTrue($authorization->allows($manager, StockTransferPermission::Create));
        $this->assertFalse($authorization->allows($manager, StockTransferPermission::Dispatch));
        $this->assertFalse($authorization->allows($staff, StockTransferPermission::View));

        [$owner2, $source, $destination, $product] = $this->foundation();
        $transfer = $this->draft($owner2, $source, $destination, [new StockTransferItemData($product->id, 1)]);
        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner2);
        $payload = ActivityLog::query()->where('event', 'stock_transfer.dispatched')->value('properties');
        $this->assertStringNotContainsString('cost', strtolower(json_encode($payload)));
        $this->assertStringNotContainsString('average', strtolower(json_encode($payload)));
    }

    public function test_overview_counts_in_transit_without_double_counting(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation(10, 0, '100.0000');
        $transfer = $this->draft($owner, $source, $destination, [new StockTransferItemData($product->id, 4)]);
        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        $row = app(InventoryLocationOverviewService::class)->forUser($owner)->firstWhere(fn (array $row): bool => $row['product']->id === $product->id);
        $this->assertSame(6, $row['total_on_hand']);
        $this->assertSame(4, $row['in_transit']);
        $this->assertSame(10, $row['total_owned']);
        $this->assertSame('400.0000', $row['in_transit_value']);
    }

    private function draft(User $actor, Warehouse $source, Warehouse $destination, array $items): StockTransfer
    {
        return app(CreateStockTransfer::class)->handle(new CreateStockTransferData($source->id, $destination->id, today()->toDateString(), $items, (string) Str::uuid()), $actor);
    }

    private function foundation(int $available = 10, int $reserved = 0, string $average = '100.0000'): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = Warehouse::factory()->create(['name' => 'Destination', 'code' => 'DEST', 'location_type' => InventoryLocationType::CompanyWarehouse, 'status' => true]);
        $product = Product::factory()->create();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $source->id, 'available_quantity' => $available, 'reserved_quantity' => $reserved, 'damaged_quantity' => 0, 'average_cost' => $average]);

        return [$owner, $source, $destination, $product, $inventory];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
