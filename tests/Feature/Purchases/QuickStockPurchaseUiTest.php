<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class QuickStockPurchaseUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_open_compact_page_while_manager_and_staff_cannot(): void
    {
        foreach ([EmployeeRole::Owner, EmployeeRole::Admin] as $role) {
            $this->actingAs($this->user($role));
            Livewire::test(QuickStockPurchase::class)
                ->assertSee('Quick Stock Purchase')
                ->assertSee('Handled By / Reported By')
                ->assertSee('Bulk Add Products')
                ->assertSee('Latest Purchase Cost')
                ->assertSee('Current Stock');
        }

        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $this->actingAs($this->user($role));
            Livewire::test(QuickStockPurchase::class)->assertForbidden();
        }
    }

    public function test_products_wait_for_warehouse_and_only_active_products_are_available(): void
    {
        $this->actingAs($this->user(EmployeeRole::Owner));
        $warehouse = Warehouse::factory()->create();
        $active = Product::factory()->create(['sku' => 'ACTIVE-SKU', 'status' => 'active']);
        $inactive = Product::factory()->create(['sku' => 'STOPPED-SKU', 'status' => 'discontinued']);
        $component = Livewire::test(QuickStockPurchase::class)->assertSee('Select a Warehouse first.');
        $field = collect($component->instance()->getSchema('content')->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');
        $this->assertInstanceOf(Select::class, $field);
        $this->assertTrue($field->isDisabled());

        $component->fillForm(['warehouse_id' => $warehouse->id]);
        $field = collect($component->instance()->getSchema('content')->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');
        $this->assertFalse($field->isDisabled());
        $this->assertArrayHasKey($active->id, $field->getSearchResults('ACTIVE-SKU'));
        $this->assertArrayNotHasKey($inactive->id, $field->getSearchResults('STOPPED-SKU'));
    }

    public function test_page_initializes_one_stable_uuid_and_default_handler(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->actingAs($owner);
        $component = Livewire::test(QuickStockPurchase::class);
        $state = $component->instance()->getSchema('content')->getRawState();

        $this->assertSame($owner->employee->id, (int) $state['handled_by_employee_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $state['idempotency_key']);
        $component->set('data.purchase_date', now()->addDay()->toDateString());
        $this->assertSame($state['idempotency_key'], $component->instance()->getSchema('content')->getRawState()['idempotency_key']);
    }

    public function test_selected_product_reactively_updates_context_summary_and_preserves_manual_cost_on_warehouse_change(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $firstWarehouse = Warehouse::factory()->create();
        $secondWarehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['sku' => 'TPZ-REACTIVE']);
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $firstWarehouse->id,
            'available_quantity' => 5,
            'reserved_quantity' => 1,
            'damaged_quantity' => 2,
            'average_cost' => '80.0000',
        ]);
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $secondWarehouse->id,
            'available_quantity' => 9,
            'reserved_quantity' => 2,
            'damaged_quantity' => 1,
            'average_cost' => '90.0000',
        ]);
        $this->receivedCost($owner, $firstWarehouse, $product, '100.0000');

        $this->actingAs($owner);
        $component = Livewire::test(QuickStockPurchase::class)
            ->fillForm(['warehouse_id' => $firstWarehouse->id]);
        $lineKey = array_key_first($component->instance()->getSchema('content')->getRawState()['items']);

        $component->set("data.items.{$lineKey}.product_id", (string) $product->id)
            ->assertSee('Avail 5; Res 1; Sellable 4; Damaged 2; On hand 7')
            ->assertSee('AED 100.00')
            ->assertSee('View received Purchase cost history')
            ->assertSee('1 Product; 1 total unit');

        $state = $component->instance()->getSchema('content')->getRawState();
        $this->assertSame($product->id, (int) $state['items'][$lineKey]['product_id']);
        $this->assertSame('100.0000', $state['items'][$lineKey]['latest_received_cost']);

        $component->set("data.items.{$lineKey}.unit_cost", '1200.0000')
            ->set("data.items.{$lineKey}.ordered_quantity", 3)
            ->assertSee('1 Product; 3 total units')
            ->assertSee('AED 3,600.00');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->set('data.warehouse_id', (string) $secondWarehouse->id)
            ->assertSee('Avail 9; Res 2; Sellable 7; Damaged 1; On hand 10');
        $contextQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'product_inventories')
                || str_contains($query['query'], 'purchase_receipt_items'),
        );
        DB::disableQueryLog();

        $state = $component->instance()->getSchema('content')->getRawState();
        $this->assertSame('1200.0000', $state['items'][$lineKey]['unit_cost']);
        $this->assertSame($product->id, (int) $state['items'][$lineKey]['product_id']);
        $this->assertLessThanOrEqual(2, $contextQueries->count());
        $this->assertStringContainsString(
            '1 Product; 3 total units',
            (string) $component->instance()->postAction()->getModalDescription(),
        );
    }

    private function receivedCost(User $actor, Warehouse $warehouse, Product $product, string $cost): void
    {
        $purchase = Purchase::factory()->create([
            'warehouse_id' => $warehouse->id,
            'status' => 'fully_received',
        ]);
        $item = PurchaseItem::factory()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'ordered_quantity' => 1,
            'received_quantity' => 1,
            'unit_cost' => $cost,
            'inventory_unit_cost' => $cost,
        ]);
        $receipt = PurchaseReceipt::factory()->create([
            'purchase_id' => $purchase->id,
            'warehouse_id' => $warehouse->id,
            'received_by_user_id' => $actor->id,
            'received_at' => now(),
        ]);
        PurchaseReceiptItem::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'purchase_item_id' => $item->id,
            'product_id' => $product->id,
            'accepted_quantity' => 1,
            'damaged_quantity' => 0,
            'rejected_quantity' => 0,
            'quantity_received' => 1,
            'inventory_unit_cost' => $cost,
            'posting_key' => (string) Str::uuid(),
        ]);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }
}
