<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Enums\InventoryItemType;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Models\Employee;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
                ->assertSee('Allocate Stock To')
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

    public function test_product_intelligence_search_and_receiving_labels_use_the_full_product_title(): void
    {
        $this->actingAs($this->user(EmployeeRole::Owner));
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create([
            'sku' => 'TPZ-000030',
            'name' => 'Dell marketplace customer title with UniqueLongSearchNeedle and many promotional specifications that must not fill the receiving dropdown',
            'accounting_title_override' => 'Dell Plus 2-in-1 DB04250 Core Ultra 7 16GB/512GB',
        ]);
        $componentProduct = Product::factory()->create([
            'sku' => 'RAM-16-DDR4',
            'inventory_item_type' => InventoryItemType::Component,
            'name' => 'RAM 16GB DDR4 3200',
        ]);
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $field = collect($component->instance()->getSchema('content')->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');

        $results = $field->getSearchResults('UniqueLongSearchNeedle');
        $fullLabel = 'TPZ-000030 — '.$product->name;
        $this->assertSame($fullLabel, $results[$product->id]);
        $this->assertStringContainsString('promotional specifications', $results[$product->id]);

        $componentResults = $field->getSearchResults('RAM-16-DDR4');
        $this->assertSame('[Component] RAM-16-DDR4 — RAM 16GB DDR4 3200', $componentResults[$componentProduct->id]);

        $lineKey = array_key_first($component->instance()->getSchema('content')->getRawState()['items']);
        $component->set("data.items.{$lineKey}.product_id", (string) $product->id);
        $selectedField = collect($component->instance()->getSchema('content')->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');
        $this->assertSame($fullLabel, $selectedField->getOptionLabel());

        $source = file_get_contents(app_path('Filament/Pages/Purchasing/QuickStockPurchase.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('getOptionLabelsUsing(fn (array $values, QuickStockPurchase $livewire): array => self::productLabels', $source);
        $this->assertStringContainsString('getOptionLabelUsing(fn ($value, QuickStockPurchase $livewire): ?string => self::productLabels', $source);
        $this->assertSame(2, substr_count($source, '->wrapOptionLabels()'));
        $this->assertStringNotContainsString('ProductTitleService', $source);
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

    public function test_received_cost_precedes_catalog_cost_and_catalog_fills_only_missing_history(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $received = Product::factory()->create(['cost_price' => '1450.0000']);
        $catalog = Product::factory()->create(['cost_price' => '1450.0000']);
        $unknown = Product::factory()->create(['cost_price' => null]);
        $this->receivedCost($owner, $warehouse, $received, '1500.0000');

        $this->actingAs($owner);
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $lineKey = array_key_first($component->instance()->getSchema('content')->getRawState()['items']);

        $component->set("data.items.{$lineKey}.product_id", (string) $received->id)
            ->assertSee('Latest Purchase Cost')
            ->assertSee('AED 1,500.00');
        $line = $component->instance()->getSchema('content')->getRawState()['items'][$lineKey];
        $this->assertSame('1500.0000', $line['unit_cost']);
        $this->assertFalse((bool) $line['unit_cost_touched']);
        $this->assertArrayHasKey('suggested_cost_source', $line, implode(', ', array_keys($line)));

        $component->set("data.items.{$lineKey}.product_id", (string) $catalog->id);
        $line = $component->instance()->getSchema('content')->getRawState()['items'][$lineKey];
        $this->assertSame('Catalog Cost', $line['suggested_cost_source']);
        $this->assertSame('1450.0000', $line['unit_cost']);
        $this->assertNull($line['latest_received_cost']);

        $component->set("data.items.{$lineKey}.product_id", (string) $unknown->id);
        $line = $component->instance()->getSchema('content')->getRawState()['items'][$lineKey];
        $this->assertNull($line['unit_cost']);
        $this->assertNull($line['suggested_cost_source']);
    }

    public function test_manual_cost_survives_context_refresh_and_admin_cannot_see_catalog_cost(): void
    {
        $warehouse = Warehouse::factory()->create();
        $otherWarehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['cost_price' => '1450.0000']);
        $this->actingAs($this->user(EmployeeRole::Owner));
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $lineKey = array_key_first($component->instance()->getSchema('content')->getRawState()['items']);
        $component->set("data.items.{$lineKey}.product_id", (string) $product->id)
            ->set("data.items.{$lineKey}.unit_cost", '1300.0000')
            ->set('data.warehouse_id', (string) $otherWarehouse->id);
        $line = $component->instance()->getSchema('content')->getRawState()['items'][$lineKey];
        $this->assertSame('1300.0000', $line['unit_cost']);
        $this->assertTrue((bool) $line['unit_cost_touched']);

        $this->actingAs($this->user(EmployeeRole::Admin));
        $admin = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $adminLineKey = array_key_first($admin->instance()->getSchema('content')->getRawState()['items']);
        $admin->set("data.items.{$adminLineKey}.product_id", (string) $product->id)
            ->assertDontSee('AED 1,450.00')
            ->assertDontSee('Catalog Cost');
        $adminLine = $admin->instance()->getSchema('content')->getRawState()['items'][$adminLineKey];
        $this->assertNull($adminLine['unit_cost']);
    }

    public function test_final_confirm_posts_a_valid_purchase_once_and_shows_success(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $holder = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $this->defaultStockResponsibility($holder, $product);
        $this->actingAs($owner);

        $component = Livewire::test(QuickStockPurchase::class);
        $idempotencyKey = $component->instance()->getSchema('content')->getRawState()['idempotency_key'];
        $data = $this->postingData($warehouse, $product, $idempotencyKey);

        $component->fillForm($data)
            ->mountAction('post')
            ->callMountedAction()
            ->assertNotified('Stock received successfully');

        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(2, ProductInventory::query()->sole()->available_quantity);

        Livewire::test(QuickStockPurchase::class)
            ->fillForm($data)
            ->mountAction('post')
            ->callMountedAction()
            ->assertNotified('Existing Quick Stock Purchase opened');

        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(2, ProductInventory::query()->sole()->available_quantity);
    }

    public function test_final_confirm_surfaces_missing_default_responsibility_without_posting(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $this->actingAs($owner);

        $component = Livewire::test(QuickStockPurchase::class)
            ->fillForm($this->postingData($warehouse, $product))
            ->mountAction('post')
            ->callMountedAction()
            ->assertNotified(Notification::make()
                ->danger()
                ->title('Quick Stock Purchase was not posted')
                ->body('No default stock responsibility is configured for this product. Configure Responsibility before receiving this stock.'));

        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('product_inventories', 0);
        $this->assertSame($product->id, (int) collect($component->instance()->getSchema('content')->getRawState()['items'])->first()['product_id']);
    }

    public function test_final_confirm_surfaces_multiple_default_responsibilities_without_posting(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $this->defaultStockResponsibility($this->user(EmployeeRole::Manager), $product);
        $this->defaultStockResponsibility($this->user(EmployeeRole::Manager), $product);
        $this->actingAs($owner);

        Livewire::test(QuickStockPurchase::class)
            ->fillForm($this->postingData($warehouse, $product))
            ->mountAction('post')
            ->callMountedAction()
            ->assertNotified(Notification::make()
                ->danger()
                ->title('Quick Stock Purchase was not posted')
                ->body('Multiple default stock responsibilities match this product. Resolve the Responsibility conflict before receiving stock.'));

        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('product_inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_final_confirm_accepts_an_explicit_active_allocation_account(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $holder = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $account = app(InventoryAllocationService::class)->employeeAccount($holder->employee->id);
        $this->actingAs($owner);

        Livewire::test(QuickStockPurchase::class)
            ->fillForm([
                ...$this->postingData($warehouse, $product),
                'allocation_account_id' => $account->id,
            ])
            ->mountAction('post')
            ->callMountedAction()
            ->assertNotified('Stock received successfully');

        $inventory = ProductInventory::query()->sole();
        $this->assertSame(2, (int) InventoryAllocationBalance::query()
            ->where('account_id', $account->id)
            ->where('product_inventory_id', $inventory->id)
            ->value('allocated_quantity'));
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

    /** @return array<string, mixed> */
    private function postingData(Warehouse $warehouse, Product $product, ?string $idempotencyKey = null): array
    {
        return [
            'warehouse_id' => $warehouse->id,
            'purchase_date' => now()->toDateString(),
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
            'shipping_total' => '0.00',
            'other_charges_total' => '0.00',
            'items' => [[
                'product_id' => $product->id,
                'ordered_quantity' => 2,
                'unit_cost' => '100.0000',
            ]],
        ];
    }

    private function defaultStockResponsibility(User $holder, Product $product): void
    {
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $holder->employee->id,
            'assign_stock_by_default' => true,
        ]);
        ResponsibilityAssignmentBrand::query()->create([
            'assignment_id' => $assignment->id,
            'product_brand_id' => $product->brand_id,
        ]);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }
}
