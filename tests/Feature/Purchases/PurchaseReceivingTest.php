<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\EmployeeRole;
use App\Enums\PurchaseStatus;
use App\Exceptions\ImmutablePurchaseException;
use App\Exceptions\OverReceiptException;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchases\PurchaseReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseReceivingTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_and_final_receipts_post_combined_immutable_inventory_movements(): void
    {
        [$owner, $purchase] = $this->approvedPurchase(10, '100.0000');
        $line = $purchase->items->first();

        $first = app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 3, 2, 1)], now()->toDateTimeString(), (string) Str::uuid()
        ), $owner);

        $inventory = ProductInventory::query()->firstOrFail();
        $this->assertSame(3, $inventory->available_quantity);
        $this->assertSame(2, $inventory->damaged_quantity);
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertSame(PurchaseStatus::PartiallyReceived, $purchase->refresh()->status);
        $this->assertDatabaseHas('purchase_items', ['id' => $line->id, 'received_quantity' => 5, 'rejected_quantity' => 1]);
        $this->assertDatabaseHas('stock_movements', ['source_id' => $first->items->first()->id, 'quantity' => 5, 'available_delta' => 3, 'damaged_delta' => 2]);

        app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 5, 0, 0)], now()->toDateTimeString(), (string) Str::uuid()
        ), $owner);

        $this->assertSame(PurchaseStatus::FullyReceived, $purchase->refresh()->status);
        $this->assertSame(2, PurchaseReceipt::query()->count());
        $this->assertSame(2, StockMovement::query()->count());
        $this->expectException(ImmutablePurchaseException::class);
        $first->forceFill(['notes' => 'changed'])->save();
    }

    public function test_rejected_only_receipt_creates_history_without_inventory_or_movement(): void
    {
        [$owner, $purchase] = $this->approvedPurchase(2, '25.0000');
        $line = $purchase->items->first();

        app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 0, 0, 2)], now()->toDateTimeString(), (string) Str::uuid()
        ), $owner);

        $this->assertSame(PurchaseStatus::Approved, $purchase->refresh()->status);
        $this->assertSame(1, PurchaseReceipt::query()->count());
        $this->assertSame(0, ProductInventory::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_weighted_average_uses_accepted_and_damaged_quantities(): void
    {
        [$owner, $purchase] = $this->approvedPurchase(5, '100.0000');
        $line = $purchase->items->first();
        ProductInventory::factory()->create([
            'product_id' => $line->product_id,
            'warehouse_id' => $purchase->warehouse_id,
            'available_quantity' => 4,
            'damaged_quantity' => 1,
            'average_cost' => '50.0000',
        ]);

        app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 3, 2, 0)], now()->toDateTimeString(), (string) Str::uuid()
        ), $owner);

        $inventory = ProductInventory::query()->firstOrFail();
        $this->assertSame('75.0000', $inventory->average_cost);
        $this->assertSame(7, $inventory->available_quantity);
        $this->assertSame(3, $inventory->damaged_quantity);
        $damage = DamagedStockEvent::query()->sole();
        $this->assertMatchesRegularExpression('/^DMG-\d{4}-\d{6}$/', $damage->reference);
        $this->assertSame(2, $damage->quantity);
        $this->assertSame('supplier_receipt', $damage->source->value);
        $this->assertSame($inventory->id, $damage->product_inventory_id);
        $this->assertDatabaseCount('safet_claims', 0);
    }

    public function test_over_receipt_rolls_back_all_business_changes(): void
    {
        [$owner, $purchase] = $this->approvedPurchase(2, '25.0000');
        $line = $purchase->items->first();

        try {
            app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
                [new PurchaseReceiptItemData($line->id, 3, 0, 0)], now()->toDateTimeString(), (string) Str::uuid()
            ), $owner);
            $this->fail('Over receipt was not rejected.');
        } catch (OverReceiptException) {
            $this->assertSame(0, PurchaseReceipt::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
            $this->assertSame(0, ProductInventory::query()->count());
        }
    }

    public function test_receipt_idempotency_returns_the_original_grn_without_posting_twice(): void
    {
        [$owner, $purchase] = $this->approvedPurchase(2, '25.0000');
        $line = $purchase->items->first();
        $data = new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 2, 0, 0)], now()->toDateTimeString(), (string) Str::uuid()
        );

        $first = app(PurchaseReceivingService::class)->receive($purchase, $data, $owner);
        $second = app(PurchaseReceivingService::class)->receive($purchase->refresh(), $data, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PurchaseReceipt::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(2, ProductInventory::query()->firstOrFail()->available_quantity);
    }

    private function approvedPurchase(int $quantity, string $cost): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();
        $product = Product::factory()->create();
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            Supplier::factory()->create()->id,
            Warehouse::factory()->create()->id,
            now()->toDateString(),
            [new PurchaseItemData($product->id, $quantity, $cost)],
            'INV-'.Str::random(8),
            now()->toDateString(),
        ), $owner);
        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Sole Owner approval.', true), $owner);

        return [$owner->refresh(), $purchase->load('items')];
    }
}
