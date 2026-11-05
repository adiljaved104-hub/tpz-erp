<?php

namespace Tests\Feature\StockTransfers;

use App\Actions\StockTransfers\CreateStockTransfer;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockTransferStatus;
use App\Exceptions\InsufficientInventoryException;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\StockTransferMarketplaceLoss;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockTransfers\StockTransferAdvancedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_marketplace_loss_has_an_explicit_morph_alias_for_activity_logging(): void
    {
        $this->assertSame('stock_transfer_marketplace_loss', (new StockTransferMarketplaceLoss)->getMorphClass());
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
