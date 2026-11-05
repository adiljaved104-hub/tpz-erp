<?php

namespace Tests\Feature\StockTransfers;

use App\Actions\StockTransfers\CreateStockTransfer;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockTransferStatus;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Exceptions\InsufficientInventoryException;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationTransitionLine;
use App\Models\StockMovement;
use App\Models\StockTransferMarketplaceLoss;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockTransfers\StockTransferAdvancedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StockTransferUatRuntimeHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_draft_partial_shipment_resolves_warehouse_inventory_without_source_link(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 3);
        $item = $transfer->items()->firstOrFail();

        $this->assertNull($item->source_product_inventory_id);

        app(StockTransferAdvancedService::class)->ship($transfer, [$item->id => 1], (string) Str::uuid(), $owner);

        $this->assertSame(StockTransferStatus::Dispatched, $transfer->refresh()->status);
        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertSame(1, (int) $item->refresh()->dispatched_quantity);
        $this->assertSame(3, (int) $item->quantity);
        $this->assertSame($inventory->id, (int) $item->source_product_inventory_id);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_filament_partial_ship_modal_shows_product_and_processes_selected_quantity(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 3);
        $item = $transfer->items()->firstOrFail();

        Livewire::actingAs($owner)
            ->test(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])
            ->mountAction('partialShip')
            ->setActionData(['quantity_'.$item->id => 1])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, (int) $item->refresh()->dispatched_quantity);
        $this->assertSame(4, $inventory->refresh()->available_quantity);
    }

    public function test_missing_source_inventory_returns_a_business_error_without_stock_movement(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $transfer = $this->draft($owner, $source, $destination, $product, 2);
        $item = $transfer->items()->firstOrFail();

        try {
            app(StockTransferAdvancedService::class)->ship($transfer, [$item->id => 1], (string) Str::uuid(), $owner);
            $this->fail('Missing inventory must be rejected.');
        } catch (InsufficientInventoryException $exception) {
            $this->assertStringContainsString('No source inventory exists', $exception->getMessage());
        }

        $this->assertSame(StockTransferStatus::Draft, $transfer->refresh()->status);
        $this->assertSame(0, (int) $item->refresh()->dispatched_quantity);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_pending_marketplace_loss_allows_missing_investigation_and_claim_actor(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $transfer = $this->draft($owner, $source, $destination, $product, 1);
        $item = $transfer->items()->firstOrFail();

        $loss = StockTransferMarketplaceLoss::query()->create([
            'stock_transfer_id' => $transfer->id,
            'stock_transfer_item_id' => $item->id,
            'quantity' => 1,
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'reported_by_user_id' => $owner->id,
        ]);

        $this->assertNull($loss->fresh()->investigating_by_user_id);
        $this->assertNull($loss->fresh()->claim_submitted_by_user_id);
    }

    public function test_received_transfer_marketplace_loss_investigation_logs_with_enforced_morph_map(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 3,
            'reserved_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 1);
        $item = $transfer->items()->firstOrFail();
        $service = app(StockTransferAdvancedService::class);

        $service->ship($transfer, [$item->id => 1], (string) Str::uuid(), $owner);
        $service->receive($transfer->refresh(), [$item->id => 1], (string) Str::uuid(), $owner);

        $loss = $service->reportMarketplaceLoss($transfer->refresh(), $item->id, 1, (string) Str::uuid(), $owner, 'UAT-LOSS-1');
        $this->assertSame('pending', $loss->status);

        $service->investigateMarketplaceLoss($loss, $owner, 'Investigated in a disposable QA database');
        $this->assertSame('investigating', $loss->refresh()->status);

        $this->assertSame('stock_transfer_marketplace_loss', $loss->getMorphClass());
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'stock_transfer.marketplace_loss_investigating',
            'subject_type' => 'stock_transfer_marketplace_loss',
            'subject_id' => $loss->id,
        ]);
    }

    public function test_marketplace_loss_has_an_explicit_morph_alias_for_activity_logging(): void
    {
        $this->assertSame('stock_transfer_marketplace_loss', (new StockTransferMarketplaceLoss)->getMorphClass());
    }

    public function test_partial_receipts_restore_only_physically_received_allocation_and_preserve_in_transit_balance(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        $sourceInventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 6,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 6);
        $item = $transfer->items()->firstOrFail();
        $service = app(StockTransferAdvancedService::class);

        $service->ship($transfer, [$item->id => 4], (string) Str::uuid(), $owner);

        $held = fn (): int => (int) InventoryAllocationTransitionLine::query()
            ->where('context_type', 'transfer')->where('context_id', $item->id)
            ->sum('remaining_quantity');
        $destinationInventory = fn (): ProductInventory => ProductInventory::query()
            ->where('warehouse_id', $destination->id)->where('product_id', $product->id)->firstOrFail();
        $allocatedAtDestination = fn (): int => (int) InventoryAllocationBalance::query()
            ->where('product_inventory_id', $destinationInventory()->id)->sum('allocated_quantity');

        $this->assertSame(4, $held());
        $this->assertSame(2, (int) $sourceInventory->refresh()->available_quantity);

        $service->receive($transfer, [$item->id => 3], (string) Str::uuid(), $owner);
        $this->assertSame(StockTransferStatus::Dispatched, $transfer->refresh()->status);
        $this->assertSame(3, (int) $item->refresh()->received_quantity);
        $this->assertSame(3, (int) $destinationInventory()->available_quantity);
        $this->assertSame(3, $allocatedAtDestination());
        $this->assertSame(1, $held());

        $service->receive($transfer, [$item->id => 1], (string) Str::uuid(), $owner);
        $this->assertSame(4, (int) $item->refresh()->received_quantity);
        $this->assertSame(4, (int) $destinationInventory()->available_quantity);
        $this->assertSame(4, $allocatedAtDestination());
        $this->assertSame(0, $held());

        $service->ship($transfer, [$item->id => 2], (string) Str::uuid(), $owner);
        $this->assertSame(2, $held());

        $service->receive($transfer, [$item->id => 2], (string) Str::uuid(), $owner);
        $this->assertSame(StockTransferStatus::Received, $transfer->refresh()->status);
        $this->assertSame(6, (int) $item->refresh()->received_quantity);
        $this->assertSame(6, (int) $destinationInventory()->available_quantity);
        $this->assertSame(6, $allocatedAtDestination());
        $this->assertSame(0, $held());
        $this->assertSame(5, StockMovement::query()->count());
    }

    public function test_over_receipt_rejects_without_inventory_or_allocation_postings(): void
    {
        [$owner, $source, $destination, $product] = $this->foundation();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 4,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $transfer = $this->draft($owner, $source, $destination, $product, 4);
        $item = $transfer->items()->firstOrFail();
        $service = app(StockTransferAdvancedService::class);

        $service->ship($transfer, [$item->id => 4], (string) Str::uuid(), $owner);
        $movementCount = StockMovement::query()->count();

        try {
            $service->receive($transfer, [$item->id => 5], (string) Str::uuid(), $owner);
            $this->fail('An over-receipt must be rejected.');
        } catch (\App\Exceptions\InventoryInvariantException $exception) {
            $this->assertStringContainsString('in-transit', $exception->getMessage());
        }

        $this->assertSame(0, (int) $item->refresh()->received_quantity);
        $this->assertSame(StockTransferStatus::Dispatched, $transfer->refresh()->status);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertFalse(ProductInventory::query()->where('warehouse_id', $destination->id)->where('product_id', $product->id)->exists());
    }

    /** @return array{User, Warehouse, Warehouse, Product} */
    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $owner->refresh();

        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = Warehouse::factory()->create([
            'name' => 'Transfer UAT destination',
            'code' => 'UAT-DEST',
            'location_type' => InventoryLocationType::CompanyWarehouse,
            'status' => true,
        ]);

        return [$owner, $source, $destination, Product::factory()->create()];
    }

    private function draft(User $actor, Warehouse $source, Warehouse $destination, Product $product, int $quantity)
    {
        return app(CreateStockTransfer::class)->handle(new CreateStockTransferData(
            sourceWarehouseId: $source->id,
            destinationWarehouseId: $destination->id,
            transferDate: today()->toDateString(),
            items: [new StockTransferItemData($product->id, $quantity)],
            idempotencyKey: (string) Str::uuid(),
        ), $actor);
    }
}
