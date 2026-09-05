<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Enums\PurchaseStatus;
use App\Filament\Pages\Purchasing\ReceivingHistoryReport;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchasingReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiving_history_uses_readable_columns_aggregates_and_grn_link(): void
    {
        $owner = $this->owner();
        [$receipt] = $this->receipt($owner, receivedAt: '2026-08-06 17:05:02');

        $this->actingAs($owner);

        $rows = app(ReceivingHistoryReport::class)->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('GRN-2026-000001', $rows[0]['grn']);
        $this->assertSame('PO-2026-000001', $rows[0]['purchase']);
        $this->assertSame('Report Supplier', $rows[0]['supplier']);
        $this->assertSame('MAIN', $rows[0]['warehouse']);
        $this->assertSame('06 Aug 2026, 05:05 PM', $rows[0]['received_at']);
        $this->assertSame($owner->name, $rows[0]['received_by']);
        $this->assertSame(2, $rows[0]['accepted_quantity']);
        $this->assertSame(1, $rows[0]['damaged_quantity']);
        $this->assertSame(1, $rows[0]['rejected_quantity']);
        $this->assertSame(PurchaseReceiptResource::getUrl('view', ['record' => $receipt->id]), $rows[0]['view']);

        Livewire::test(ReceivingHistoryReport::class)
            ->assertSee('GRN-2026-000001')
            ->assertSee('PO-2026-000001')
            ->assertSee('Accepted Qty')
            ->assertSee('Damaged Qty')
            ->assertSee('Rejected Qty')
            ->assertDontSee('Accepted Quantity')
            ->assertSee('View')
            ->assertSeeHtml('overflow-x-auto')
            ->assertSeeHtml('min-w-[900px]')
            ->assertSeeHtml('sticky right-0')
            ->assertSeeHtml('fi-btn');

        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('purchase_receipt_items', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_receiving_history_filters_by_date_supplier_warehouse_and_product(): void
    {
        $owner = $this->owner();
        [$matching, $product, $supplier, $warehouse] = $this->receipt($owner, receivedAt: '2026-08-06 17:05:02');
        $this->receipt($owner, 'GRN-2026-000002', 'PO-2026-000002', '2026-07-01 09:00:00');

        $this->actingAs($owner);

        $page = app(ReceivingHistoryReport::class);
        $page->dateFrom = '2026-08-01';
        $page->dateTo = '2026-08-31';
        $page->supplierId = (string) $supplier->id;
        $page->warehouseId = (string) $warehouse->id;
        $page->productId = (string) $product->id;

        $rows = $page->rows();

        $this->assertCount(1, $rows);
        $this->assertSame($matching->reference, $rows[0]['grn']);
    }

    public function test_receiving_history_is_not_available_to_staff(): void
    {
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create();

        $this->actingAs($staff);

        $this->assertFalse(ReceivingHistoryReport::canAccess());
        $this->expectException(HttpException::class);

        app(ReceivingHistoryReport::class)->rows();
    }

    /** @return array{PurchaseReceipt, Product, Supplier, Warehouse} */
    private function receipt(
        User $receiver,
        string $grn = 'GRN-2026-000001',
        string $purchaseReference = 'PO-2026-000001',
        string $receivedAt = '2026-08-06 17:05:02',
    ): array {
        $supplier = Supplier::factory()->create(['name' => $grn === 'GRN-2026-000001' ? 'Report Supplier' : "Report Supplier {$grn}"]);
        $warehouse = $grn === 'GRN-2026-000001'
            ? Warehouse::query()->where('code', 'MAIN')->sole()
            : Warehouse::factory()->create(['code' => 'WH-'.substr($grn, -2)]);
        $product = Product::factory()->create();
        $purchase = Purchase::factory()->create([
            'reference' => $purchaseReference,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseStatus::FullyReceived,
            'created_by_user_id' => $receiver->id,
        ]);
        $item = PurchaseItem::factory()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'ordered_quantity' => 4,
            'received_quantity' => 3,
            'rejected_quantity' => 1,
        ]);
        $receipt = PurchaseReceipt::factory()->create([
            'reference' => $grn,
            'purchase_id' => $purchase->id,
            'warehouse_id' => $warehouse->id,
            'received_at' => $receivedAt,
            'received_by_user_id' => $receiver->id,
        ]);
        PurchaseReceiptItem::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'purchase_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity_received' => 4,
            'accepted_quantity' => 2,
            'damaged_quantity' => 1,
            'rejected_quantity' => 1,
        ]);

        return [$receipt, $product, $supplier, $warehouse];
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();

        return $owner->refresh();
    }
}
