<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseReceiptViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_clear_labels_readable_date_and_non_wrapping_cost(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $receipt = $this->receipt($owner);

        $this->actingAs($owner);

        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->getRouteKey()])
            ->assertOk()
            ->assertSee('GRN Reference')
            ->assertSee('GRN-2026-000001')
            ->assertSee('Purchase Reference')
            ->assertSee('PO-2026-000001')
            ->assertSee('Warehouse')
            ->assertSee('Main Warehouse')
            ->assertSee('Supplier Delivery Note')
            ->assertSee('Received At')
            ->assertSee('06 Aug 2026, 05:05 PM')
            ->assertSee('Received By')
            ->assertSee('Product')
            ->assertSee('Accepted Qty')
            ->assertSee('Damaged Qty')
            ->assertSee('Rejected Qty')
            ->assertSee('Inventory Unit Cost')
            ->assertSee('20.00')
            ->assertSeeHtml('white-space:nowrap')
            ->assertSeeHtml('overflow-x:auto');
    }

    public function test_non_financial_user_does_not_query_or_render_inventory_unit_cost(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $receipt = $this->receipt($owner);

        $this->actingAs($manager);
        DB::enableQueryLog();

        $component = Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->getRouteKey()])
            ->assertOk()
            ->assertDontSee('Inventory Unit Cost')
            ->assertDontSee('20.00');

        $itemQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'purchase_receipt_items'));

        DB::disableQueryLog();

        $this->assertNotEmpty($itemQueries);
        $this->assertTrue($itemQueries->every(
            fn (string $query): bool => ! str_contains(strtolower($query), 'inventory_unit_cost'),
        ));
        $component->assertSee('Report Product');
    }

    private function receipt(User $receiver): PurchaseReceipt
    {
        $supplier = Supplier::factory()->create();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create(['name' => 'Report Product']);
        $purchase = Purchase::factory()->create([
            'reference' => 'PO-2026-000001',
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
            'reference' => 'GRN-2026-000001',
            'purchase_id' => $purchase->id,
            'warehouse_id' => $warehouse->id,
            'supplier_delivery_note' => 'SDN-001',
            'received_at' => '2026-08-06 17:05:02',
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
            'inventory_unit_cost' => '20.0000',
        ]);

        return $receipt;
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }
}
