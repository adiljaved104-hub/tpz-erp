<?php

namespace Tests\Feature\StockTransfers;

use App\Actions\StockTransfers\CreateStockTransfer as CreateStockTransferAction;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Actions\StockTransfers\ReceiveStockTransfer;
use App\Actions\StockTransfers\ReturnStockTransferToSource;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockTransferStatus;
use App\Exceptions\InactiveInventorySubjectException;
use App\Filament\Pages\Administration\AccessControl;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Mockery\MockInterface;
use Tests\TestCase;

class StockTransferFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_page_access_and_access_control_group_are_server_side_authorized(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($owner);
        $this->assertTrue(StockTransferResource::canViewAny());
        $this->assertTrue(StockTransferResource::canCreate());
        Livewire::test(AccessControl::class)->call('selectGroup', 'Stock Transfers')->assertSee('View Transfers')->assertSee('Dispatch Transfers');
        $this->actingAs($manager);
        $this->assertTrue(StockTransferResource::canViewAny());
        $this->assertTrue(StockTransferResource::canCreate());
        $this->actingAs($staff);
        $this->assertFalse(StockTransferResource::canViewAny());
        $this->assertFalse(StockTransferResource::canCreate());
        $this->get('/admin/stock-transfers/create')->assertForbidden();
        Livewire::test(CreateStockTransfer::class)->assertForbidden();
    }

    public function test_dispatch_without_source_inventory_shows_a_danger_notification_and_changes_nothing(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $transfer = $this->draft($owner, $source, $destination, $product, 1);

        Livewire::actingAs($owner)
            ->test(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])
            ->mountAction('dispatch')
            ->callMountedAction()
            ->assertNotified(Notification::make()
                ->danger()
                ->title('Cannot dispatch transfer')
                ->body("No source inventory exists for {$product->sku} at Main Warehouse."));

        $this->assertSame(StockTransferStatus::Draft, $transfer->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_dispatch_with_insufficient_sellable_stock_is_friendly_and_atomic(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 3,
            'reserved_quantity' => 1,
            'damaged_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 3);

        Livewire::actingAs($owner)
            ->test(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])
            ->mountAction('dispatch')
            ->callMountedAction()
            ->assertNotified(Notification::make()
                ->danger()
                ->title('Cannot dispatch transfer')
                ->body("Insufficient Sellable inventory for {$product->sku} at Main Warehouse."));

        $inventory->refresh();
        $this->assertSame(3, $inventory->available_quantity);
        $this->assertSame(1, $inventory->reserved_quantity);
        $this->assertSame(StockTransferStatus::Draft, $transfer->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_successful_filament_dispatch_still_posts_normally(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 3,
            'reserved_quantity' => 1,
            'damaged_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 2);

        Livewire::actingAs($owner)
            ->test(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])
            ->mountAction('dispatch')
            ->callMountedAction();

        $this->assertSame(StockTransferStatus::Dispatched, $transfer->refresh()->status);
        $this->assertSame(1, $inventory->refresh()->available_quantity);
        $this->assertSame(1, $inventory->reserved_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_receive_and_return_known_failures_are_shown_without_changing_the_transfer(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 2,
            'reserved_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 1);
        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        $movementCount = StockMovement::query()->count();

        $this->mock(ReceiveStockTransfer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(
                new InactiveInventorySubjectException('The receiving Inventory Location must be active.'),
            );
        });

        Livewire::actingAs($owner)
            ->test(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])
            ->mountAction('receive')
            ->callMountedAction()
            ->assertNotified('Cannot receive transfer');

        $this->mock(ReturnStockTransferToSource::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(
                new InactiveInventorySubjectException('The receiving Inventory Location must be active.'),
            );
        });

        Livewire::actingAs($owner)
            ->test(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])
            ->mountAction('return')
            ->setActionData(['reason' => 'Courier returned shipment'])
            ->callMountedAction()
            ->assertNotified('Cannot return transfer to source');

        $this->assertSame(StockTransferStatus::Dispatched, $transfer->refresh()->status);
        $this->assertSame($movementCount, StockMovement::query()->count());
    }

    public function test_unexpected_dispatch_exception_is_not_silently_hidden(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $transfer = $this->draft($owner, $source, $destination, $product, 1);

        $this->mock(DispatchStockTransfer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new LogicException('Unexpected programming failure.'));
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unexpected programming failure.');

        Livewire::actingAs($owner)
            ->test(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])
            ->mountAction('dispatch')
            ->callMountedAction();
    }

    private function draft(User $actor, Warehouse $source, Warehouse $destination, Product $product, int $quantity): StockTransfer
    {
        return app(CreateStockTransferAction::class)->handle(new CreateStockTransferData(
            sourceWarehouseId: $source->id,
            destinationWarehouseId: $destination->id,
            transferDate: today()->toDateString(),
            items: [new StockTransferItemData($product->id, $quantity)],
            idempotencyKey: (string) Str::uuid(),
        ), $actor);
    }

    /** @return array{User, Warehouse, Warehouse, Product} */
    private function foundation(): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = Warehouse::factory()->create([
            'name' => 'Destination',
            'code' => 'DEST',
            'location_type' => InventoryLocationType::CompanyWarehouse,
            'status' => true,
        ]);

        return [$owner, $source, $destination, Product::factory()->create()];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
