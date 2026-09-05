<?php

namespace Tests\Feature\Purchases;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReadService;
use App\Services\Purchases\PurchaseCostHistoryService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseCostHistoryPopoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_retrieve_received_cost_history_without_owner_only_financial_fields(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->allowCostHistory($staff, $owner);
        $product = Product::factory()->create(['cost_price' => '451.0000', 'selling_price' => '999.00']);
        $this->assignProduct($staff, $product);
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 6,
            'reserved_quantity' => 1,
            'damaged_quantity' => 1,
            'average_cost' => '77.7777',
        ]);

        $this->receiptCost($product, $staff, PurchaseStatus::FullyReceived, now()->subDays(2), 2, 0, 0, '10.0000');
        $this->receiptCost($product, $staff, PurchaseStatus::FullyReceived, now(), 1, 0, 0, '20.0000');
        $latest = $this->receiptCost($product, $staff, PurchaseStatus::FullyReceived, now(), 2, 1, 0, '30.0000');
        $this->receiptCost($product, $staff, PurchaseStatus::FullyReceived, now()->addMinute(), 0, 0, 5, '900.0000');
        $this->receiptCost($product, $staff, PurchaseStatus::Draft, now()->addDay(), 1, 0, 0, '999.0000');
        PurchaseItem::factory()->create(['product_id' => $product->id]);

        $summary = app(PurchaseCostHistoryService::class)->summaryForProduct($product->id, $staff);

        $this->assertSame('30.0000', $summary->latestReceivedCost);
        $this->assertSame(3, $summary->latestReceivedQuantity);
        $this->assertSame($latest->receipt->reference, $summary->recentEntries[0]['grn_reference']);
        $this->assertSame('21.6667', $summary->weightedAverageReceivedCost);
        $this->assertCount(3, $summary->recentEntries);

        $inventory = app(InventoryReadService::class)->inventories($staff)->firstOrFail();
        $this->assertArrayNotHasKey('average_cost', $inventory->getAttributes());
        $this->assertArrayNotHasKey('inventory_value', $inventory->getAttributes());

        $this->actingAs($staff);
        $staffProduct = ProductResource::getEloquentQuery()->findOrFail($product->id);
        $this->assertArrayHasKey('latest_purchase_cost', $staffProduct->getAttributes());
        $this->assertArrayNotHasKey('cost_price', $staffProduct->getAttributes());
        $this->assertSame('999.00', $product->fresh()->selling_price);
    }

    public function test_owner_receives_purchase_history_and_inventory_financial_context(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create(['cost_price' => '45.1234']);
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'average_cost' => '40.0000',
        ]);
        $this->receiptCost($product, $owner, PurchaseStatus::FullyReceived, now(), 1, 0, 0, '42.5000');

        $this->actingAs($owner);
        $productRow = ProductResource::getEloquentQuery()->findOrFail($product->id);
        $inventoryRow = ProductInventoryResource::getEloquentQuery()->firstOrFail();

        $this->assertSame('45.1234', $productRow->cost_price);
        $this->assertEquals('42.5', (string) $productRow->latest_purchase_cost);
        $this->assertSame('40.0000', $inventoryRow->average_cost);
        $this->assertEquals('42.5', (string) $inventoryRow->latest_purchase_cost);

        $component = Livewire::test(ListProductInventories::class)
            ->assertTableColumnExists('average_cost')
            ->assertTableColumnExists('inventory_value')
            ->assertTableColumnExists('latest_purchase_cost')
            ->assertSee('AED 42.50');
        $component->mountTableAction('purchaseCostHistory', $inventoryRow);
        $modal = $component->instance()->getMountedAction()?->getModalContent()?->render();
        $this->assertIsString($modal);
        $this->assertStringContainsString('AED 42.50', $modal);
    }

    public function test_last_five_history_is_ordered_by_receipt_date_then_receipt_id(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->allowCostHistory($staff, $owner);
        $product = Product::factory()->create();
        $this->assignProduct($staff, $product);
        $date = CarbonImmutable::parse('2026-08-01 10:00:00');
        $receipts = collect();

        foreach (range(1, 6) as $cost) {
            $receipts->push($this->receiptCost(
                $product,
                $staff,
                PurchaseStatus::FullyReceived,
                $date,
                1,
                0,
                0,
                $cost.'.0000',
            ));
        }

        $summary = app(PurchaseCostHistoryService::class)->summaryForProduct($product->id, $staff);

        $this->assertCount(5, $summary->recentEntries);
        $this->assertSame($receipts->last()->receipt->reference, $summary->recentEntries[0]['grn_reference']);
        $this->assertSame('6.0000', $summary->latestReceivedCost);
        $this->assertSame('3.5000', $summary->weightedAverageReceivedCost);
    }

    public function test_latest_cost_is_batch_selected_without_n_plus_one_queries_or_owner_fields(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $products = Product::factory()->count(8)->create(['cost_price' => '99.0000']);

        foreach ($products as $product) {
            $this->assignProduct($manager, $product);
            ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id]);
            $this->receiptCost($product, $manager, PurchaseStatus::FullyReceived, now(), 1, 0, 0, '25.0000');
        }

        $this->actingAs($manager);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $productRows = ProductResource::getEloquentQuery()->get();
        $productQueries = DB::getQueryLog();

        $this->assertCount(8, $productRows);
        $this->assertLessThanOrEqual(4, count($productQueries));
        $productQuery = collect($productQueries)->first(fn (array $query): bool => str_contains($query['query'], 'from "products"'));
        $this->assertNotNull($productQuery);
        $this->assertStringContainsString('purchase_receipt_items', $productQuery['query']);
        $this->assertStringNotContainsString('cost_price', $productQuery['query']);

        DB::flushQueryLog();
        $inventoryRows = ProductInventoryResource::getEloquentQuery()->get();
        $inventoryQueries = DB::getQueryLog();

        $this->assertCount(8, $inventoryRows);
        $this->assertLessThanOrEqual(3, count($inventoryQueries));
        $this->assertStringNotContainsString('average_cost', $inventoryQueries[0]['query']);
    }

    public function test_click_action_loads_history_without_a_page_reload_and_enforces_authorization(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $product = Product::factory()->create();
        $this->assignProduct($manager, $product);
        $this->receiptCost($product, $manager, PurchaseStatus::FullyReceived, now(), 1, 1, 0, '55.0000');

        $this->actingAs($manager);
        $component = Livewire::test(ListProducts::class)
            ->assertTableColumnExists('latest_purchase_cost')
            ->assertTableActionExists('purchaseCostHistory')
            ->assertSee('Latest Purchase Cost')
            ->assertSee('AED 55.00');

        $columnAction = $component->instance()->getTable()->getColumn('latest_purchase_cost')?->getAction();
        $this->assertInstanceOf(Action::class, $columnAction);

        $component->mountTableAction('purchaseCostHistory', $product);
        $mountedAction = $component->instance()->getMountedAction();
        $this->assertInstanceOf(Action::class, $mountedAction);
        $this->assertTrue($mountedAction->isModalSlideOver());
        $this->assertTrue($mountedAction->shouldOpenModal());
        $modal = $mountedAction->getModalContent()?->render();
        $this->assertIsString($modal);
        $this->assertStringContainsString('Latest Received Purchase Cost', $modal);
        $this->assertStringContainsString('AED 55.00', $modal);

        $inactiveStaff = $this->user(EmployeeRole::Staff);
        $inactiveStaff->employee()->update(['status' => false]);

        $this->expectException(AuthorizationException::class);
        app(PurchaseCostHistoryService::class)->summaryForProduct($product->id, $inactiveStaff->refresh());
    }

    private function receiptCost(
        Product $product,
        User $receiver,
        PurchaseStatus $status,
        CarbonImmutable|\DateTimeInterface|string $receivedAt,
        int $accepted,
        int $damaged,
        int $rejected,
        string $cost,
    ): PurchaseReceiptItem {
        $purchase = Purchase::factory()->create(['status' => $status]);
        $item = PurchaseItem::factory()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'ordered_quantity' => max(1, $accepted + $damaged + $rejected),
            'received_quantity' => $accepted + $damaged,
            'rejected_quantity' => $rejected,
            'unit_cost' => $cost,
            'inventory_unit_cost' => $cost,
        ]);
        $receipt = PurchaseReceipt::factory()->create([
            'purchase_id' => $purchase->id,
            'warehouse_id' => $purchase->warehouse_id,
            'received_at' => $receivedAt,
            'received_by_user_id' => $receiver->id,
        ]);

        return PurchaseReceiptItem::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'purchase_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity_received' => $accepted + $damaged + $rejected,
            'accepted_quantity' => $accepted,
            'damaged_quantity' => $damaged,
            'rejected_quantity' => $rejected,
            'inventory_unit_cost' => $cost,
            'posting_key' => (string) Str::uuid(),
        ])->load('receipt');
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function assignProduct(User $user, Product $product): void
    {
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $user->employee->id,
            'assigned_by_user_id' => $user->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create([
            'assignment_id' => $assignment->id,
            'product_id' => $product->id,
        ]);
    }

    private function allowCostHistory(User $staff, User $owner): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => PurchasePermission::ViewCostHistory->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Cost history regression test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
    }
}
