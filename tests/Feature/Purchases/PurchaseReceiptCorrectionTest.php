<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Enums\PurchaseStatus;
use App\Exceptions\ImmutablePurchaseException;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptCorrection;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseReceiptCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('purchase_receipt_corrections')) {
            (require database_path('migrations/2026_09_29_150000_create_purchase_receipt_corrections.php'))->up();
        }
    }

    public function test_deployed_correction_history_remains_readable_and_immutable(): void
    {
        [$owner, $receipt, $item] = $this->receipt();
        $correction = PurchaseReceiptCorrection::query()->create([
            'reference' => 'GRC-2026-000001',
            'purchase_id' => $receipt->purchase_id,
            'purchase_receipt_id' => $receipt->id,
            'purchase_receipt_item_id' => $item->id,
            'purchase_item_id' => $item->purchase_item_id,
            'product_id' => $item->product_id,
            'warehouse_id' => $receipt->warehouse_id,
            'original_received_quantity' => 4,
            'quantity_before' => 4,
            'corrected_quantity' => 3,
            'adjustment_quantity' => -1,
            'inventory_unit_cost' => '20.0000',
            'reason' => 'Historical correction made before stock adjustments.',
            'performed_by_user_id' => $owner->id,
            'corrected_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
            'movement_group' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        Livewire::actingAs($owner)->test(ViewPurchaseReceipt::class, ['record' => $receipt->id])
            ->assertSee('Legacy GRN Correction History')
            ->assertSee($correction->reference)
            ->assertSee('Posted Accepted Quantity')
            ->assertDontSee('Correct Received Quantity');

        $this->expectException(ImmutablePurchaseException::class);
        $correction->forceFill(['reason' => 'Changed'])->save();
    }

    public function test_grn_page_only_opens_stock_adjustment_and_does_not_post_it(): void
    {
        [$owner, $receipt, $item] = $this->receipt();
        Livewire::actingAs($owner)->test(ViewPurchaseReceipt::class, ['record' => $receipt->id])
            ->assertActionVisible('createStockAdjustment')
            ->assertDontSee('Correct Received Quantity');

        $this->assertDatabaseCount('inventory_adjustments', 0);
        $this->assertSame(4, $item->refresh()->accepted_quantity);
        $this->assertSame(4, $receipt->purchase->items()->sole()->received_quantity);
    }

    private function receipt(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();
        $owner->refresh();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create();
        $purchase = Purchase::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseStatus::FullyReceived,
            'created_by_user_id' => $owner->id,
        ]);
        $purchaseItem = PurchaseItem::factory()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'ordered_quantity' => 4,
            'received_quantity' => 4,
            'unit_cost' => '20.0000',
            'inventory_unit_cost' => '20.0000',
        ]);
        $receipt = PurchaseReceipt::factory()->create([
            'purchase_id' => $purchase->id,
            'warehouse_id' => $warehouse->id,
            'received_by_user_id' => $owner->id,
        ]);
        $item = PurchaseReceiptItem::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'purchase_item_id' => $purchaseItem->id,
            'product_id' => $product->id,
            'accepted_quantity' => 4,
            'quantity_received' => 4,
            'inventory_unit_cost' => '20.0000',
        ]);
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 4,
            'average_cost' => '20.0000',
        ]);

        return [$owner, $receipt, $item];
    }
}
