<?php

namespace Tests\Feature\InventoryLocations;

use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Orders\SaveAsShippedOrder;
use App\Actions\StockTransfers\CreateStockTransfer;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Actions\StockTransfers\ReceiveStockTransfer;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockMovementType;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\OrderFulfillmentItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryLocationWorkflowEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_transfer_order_reservation_and_shipment_chain_preserves_exact_balances(): void
    {
        [$owner, $main, $amazon, $amazonFba] = $this->foundation();
        $product = Product::factory()->create(['cost_price' => '9999.0000']);
        $mainInventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $main->id,
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => '1000.0000',
        ]);

        $this->assertSame(20, $this->companyOwned());
        $transfer = $this->transfer($owner, $main, $amazonFba, $product, 5);
        $this->assertSame(0, $transfer->items()->sole()->inTransitQuantity());

        $dispatchKey = (string) Str::uuid();
        $transfer = app(DispatchStockTransfer::class)->handle($transfer, $dispatchKey, $owner);
        $transferItem = $transfer->items()->sole();

        $this->assertSame(15, $mainInventory->refresh()->available_quantity);
        $this->assertSame(0, $mainInventory->reserved_quantity);
        $this->assertSame('1000.0000', $mainInventory->average_cost);
        $this->assertSame(5, $transferItem->inTransitQuantity());
        $this->assertSame('1000.0000', $transferItem->dispatch_unit_cost);
        $this->assertSame(20, $this->companyOwned());
        $this->assertSame(StockMovementType::TransferDispatch, StockMovement::query()->sole()->movement_type);

        $transfer = app(ReceiveStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        $fbaInventory = ProductInventory::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $amazonFba->id)
            ->sole();

        $this->assertSame(15, $mainInventory->refresh()->available_quantity);
        $this->assertSame(5, $fbaInventory->available_quantity);
        $this->assertSame('1000.0000', $fbaInventory->average_cost);
        $this->assertSame(0, $transfer->items()->sole()->inTransitQuantity());
        $this->assertSame(20, $this->companyOwned());
        $this->assertSame(
            [StockMovementType::TransferDispatch, StockMovementType::TransferReceipt],
            StockMovement::query()->orderBy('id')->pluck('movement_type')->all(),
        );
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('orders', 0);

        $mainOrder = app(SaveAsShippedOrder::class)->handle(
            $this->orderData($owner, $product, $main, $amazon, 1, 'MAIN-SHIP'),
            $owner,
        );
        $mainFulfillment = $mainOrder->fulfillment->items()->sole();
        $this->assertSame(14, $mainInventory->refresh()->available_quantity);
        $this->assertSame(5, $fbaInventory->refresh()->available_quantity);
        $this->assertSame($main->id, $mainFulfillment->warehouse_id);
        $this->assertSame($mainInventory->id, $mainFulfillment->product_inventory_id);
        $this->assertSame('1000.0000', $mainFulfillment->inventory_unit_cost);
        $this->assertSame('1000.0000', $mainFulfillment->cogs_total);
        $this->assertSame($amazon->id, $mainOrder->marketplace_platform_id);
        $this->assertSame(19, $this->companyOwned());

        $fbaOrder = app(SaveAsShippedOrder::class)->handle(
            $this->orderData($owner, $product, $amazonFba, $amazon, 1, 'FBA-SHIP'),
            $owner,
        );
        $fbaFulfillment = $fbaOrder->fulfillment->items()->sole();
        $this->assertSame(14, $mainInventory->refresh()->available_quantity);
        $this->assertSame(4, $fbaInventory->refresh()->available_quantity);
        $this->assertSame($amazonFba->id, $fbaFulfillment->warehouse_id);
        $this->assertSame($fbaInventory->id, $fbaFulfillment->product_inventory_id);
        $this->assertSame('1000.0000', $fbaFulfillment->inventory_unit_cost);
        $this->assertSame(18, $this->companyOwned());

        $reservedOrder = app(SaveAndReserveOrder::class)->handle(
            $this->orderData($owner, $product, $amazonFba, $amazon, 2, 'FBA-RESERVE'),
            $owner,
        );
        $reservation = $reservedOrder->items()->sole()->reservation;
        $this->assertSame(4, $fbaInventory->refresh()->available_quantity);
        $this->assertSame(2, $fbaInventory->reserved_quantity);
        $this->assertSame(2, $fbaInventory->sellableQuantity());
        $this->assertSame($amazonFba->id, $reservation->warehouse_id);
        $this->assertSame($fbaInventory->id, $reservation->product_inventory_id);
        $this->assertSame(14, $mainInventory->refresh()->available_quantity);
        $this->assertSame(18, $this->companyOwned());

        $reservedOrder = app(FulfillOrder::class)->handle($reservedOrder, (string) Str::uuid(), $owner);
        $reservedFulfillment = $reservedOrder->fulfillment->items()->sole();
        $this->assertSame(2, $fbaInventory->refresh()->available_quantity);
        $this->assertSame(0, $fbaInventory->reserved_quantity);
        $this->assertSame(2, $fbaInventory->sellableQuantity());
        $this->assertSame('fulfilled', $reservation->refresh()->status->value);
        $this->assertSame($amazonFba->id, $reservedFulfillment->warehouse_id);
        $this->assertSame($fbaInventory->id, $reservedFulfillment->product_inventory_id);
        $this->assertSame('1000.0000', $reservedFulfillment->inventory_unit_cost);
        $this->assertSame('2000.0000', $reservedFulfillment->cogs_total);
        $this->assertSame(14, $mainInventory->refresh()->available_quantity);
        $this->assertSame(16, $this->companyOwned());
    }

    public function test_destination_weighted_average_and_location_specific_cogs_are_exact(): void
    {
        [$owner, $main, $amazon, $amazonFba] = $this->foundation();
        $transferProduct = Product::factory()->create();
        $source = ProductInventory::factory()->create([
            'product_id' => $transferProduct->id,
            'warehouse_id' => $main->id,
            'available_quantity' => 10,
            'average_cost' => '1000.0000',
        ]);
        $destination = ProductInventory::factory()->create([
            'product_id' => $transferProduct->id,
            'warehouse_id' => $amazonFba->id,
            'available_quantity' => 5,
            'average_cost' => '1200.0000',
        ]);

        $transfer = $this->transfer($owner, $main, $amazonFba, $transferProduct, 5);
        $transfer = app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
        app(ReceiveStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);

        $this->assertSame(5, $source->refresh()->available_quantity);
        $this->assertSame('1000.0000', $source->average_cost);
        $this->assertSame(10, $destination->refresh()->available_quantity);
        $this->assertSame('1100.0000', $destination->average_cost);
        $this->assertSame(15, $this->companyOwned());

        $cogsProduct = Product::factory()->create(['cost_price' => '7777.0000']);
        $mainCogsInventory = ProductInventory::factory()->create([
            'product_id' => $cogsProduct->id,
            'warehouse_id' => $main->id,
            'available_quantity' => 3,
            'average_cost' => '1000.0000',
        ]);
        $fbaCogsInventory = ProductInventory::factory()->create([
            'product_id' => $cogsProduct->id,
            'warehouse_id' => $amazonFba->id,
            'available_quantity' => 3,
            'average_cost' => '1200.0000',
        ]);
        $historyCount = $this->tableCount('purchase_receipt_items');

        app(SaveAsShippedOrder::class)->handle(
            $this->orderData($owner, $cogsProduct, $main, $amazon, 1, 'COST-MAIN'),
            $owner,
        );
        app(SaveAsShippedOrder::class)->handle(
            $this->orderData($owner, $cogsProduct, $amazonFba, $amazon, 1, 'COST-FBA'),
            $owner,
        );

        $costs = OrderFulfillmentItem::query()
            ->where('product_id', $cogsProduct->id)
            ->orderBy('id')
            ->pluck('inventory_unit_cost')
            ->all();
        $this->assertSame(['1000.0000', '1200.0000'], $costs);
        $this->assertSame(2, $mainCogsInventory->refresh()->available_quantity);
        $this->assertSame(2, $fbaCogsInventory->refresh()->available_quantity);
        $this->assertSame($historyCount, $this->tableCount('purchase_receipt_items'));
    }

    /** @return array{User, Warehouse, MarketplacePlatform, Warehouse} */
    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $main = Warehouse::query()->where('code', 'MAIN')->sole();
        $amazon = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon UAE']);
        $amazonFba = Warehouse::factory()->create([
            'name' => 'Amazon FBA UAE',
            'code' => 'AMZ-FBA',
            'location_type' => InventoryLocationType::MarketplaceFulfilment,
            'marketplace_platform_id' => $amazon->id,
            'fulfillment_tag' => 'FBA',
        ]);
        Warehouse::factory()->create([
            'name' => 'Noon FBN UAE',
            'code' => 'NOON-FBN',
            'location_type' => InventoryLocationType::MarketplaceFulfilment,
            'marketplace_platform_id' => $noon->id,
            'fulfillment_tag' => 'FBN',
        ]);

        return [$owner->refresh(), $main, $amazon, $amazonFba];
    }

    private function transfer(User $actor, Warehouse $source, Warehouse $destination, Product $product, int $quantity): StockTransfer
    {
        return app(CreateStockTransfer::class)->handle(new CreateStockTransferData(
            $source->id,
            $destination->id,
            now()->toDateString(),
            [new StockTransferItemData($product->id, $quantity)],
            (string) Str::uuid(),
        ), $actor);
    }

    private function orderData(
        User $actor,
        Product $product,
        Warehouse $location,
        MarketplacePlatform $platform,
        int $quantity,
        string $externalNumber,
    ): SaveAndReserveOrderData {
        return new SaveAndReserveOrderData(
            $location->id,
            $platform->id,
            $externalNumber,
            now()->toDateString(),
            $actor->employee->id,
            null,
            [new OrderItemData($product->id, $quantity, '1500.00')],
            (string) Str::uuid(),
        );
    }

    private function companyOwned(): int
    {
        $onHand = ProductInventory::query()->sum('available_quantity')
            + ProductInventory::query()->sum('damaged_quantity');
        $inTransit = StockTransfer::query()
            ->with('items')
            ->get()
            ->sum(fn (StockTransfer $transfer): int => $transfer->items->sum->inTransitQuantity());

        return $onHand + $inTransit;
    }

    private function tableCount(string $table): int
    {
        return (int) DB::table($table)->count();
    }
}
