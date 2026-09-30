<?php

namespace Tests\Feature\Inventory;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Inventory\InventoryAdjustmentData;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\StockMovementType;
use App\Exceptions\ImmutableInventoryRecordException;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationReservationLine;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptAllocationLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Purchases\PurchaseReceivingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('inventory_adjustments')) {
            (require database_path('migrations/2026_09_30_010000_create_inventory_adjustments.php'))->up();
        }
    }

    public function test_linked_positive_discrepancy_preserves_grn_purchase_and_owner_allocation(): void
    {
        [$owner, $purchase, $item] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $accountId = PurchaseReceiptAllocationLine::query()->sole()->account_id;
        $data = $this->data($inventory, 'saleable_increase', 1, $item->id, false);

        $first = app(InventoryAdjustmentService::class)->post($data, $owner);
        $second = app(InventoryAdjustmentService::class)->post($data, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertStringStartsWith('ADJ-', $first->reference);
        $this->assertSame(4, $item->refresh()->accepted_quantity);
        $this->assertSame(4, $purchase->items()->sole()->received_quantity);
        $this->assertSame(5, $inventory->refresh()->available_quantity);
        $this->assertSame(5, InventoryAllocationBalance::query()->where('account_id', $accountId)->sole()->allocated_quantity);
        $this->assertSame($item->receipt->reference, $first->grn_reference);
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovementType::InventoryAdjustment->value,
            'source_id' => $first->id,
            'available_delta' => 1,
        ]);
        $this->assertDatabaseCount('inventory_adjustments', 1);
        $this->assertSame(2, StockMovement::query()->count());

        Livewire::actingAs($owner)->test(ViewPurchaseReceipt::class, ['record' => $item->purchase_receipt_id])
            ->assertSee('Linked Stock Adjustments')->assertSee($first->reference);
    }

    public function test_linked_negative_discrepancy_uses_unreserved_stock_without_changing_purchase(): void
    {
        [$owner, $purchase, $item] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $adjustment = app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_decrease', 2, $item->id), $owner);

        $this->assertSame(-2, $adjustment->available_delta);
        $this->assertSame(2, $inventory->refresh()->available_quantity);
        $this->assertSame(2, InventoryAllocationBalance::query()->sole()->allocated_quantity);
        $this->assertSame(4, $item->refresh()->accepted_quantity);
        $this->assertSame(4, $purchase->items()->sole()->received_quantity);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'inventory_adjustment_decrease', 'quantity' => 2]);
    }

    public function test_linked_positive_discrepancy_preserves_current_wac_and_records_grn_cost_only_as_reference(): void
    {
        [$owner, , $item] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $inventory->forceFill(['average_cost' => '30.0000'])->save();

        $adjustment = app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_increase', 1, $item->id, false), $owner);

        $this->assertSame('30.0000', $inventory->refresh()->average_cost);
        $this->assertSame('20.0000', $adjustment->reference_unit_cost);
        $this->assertSame('30.0000', $adjustment->movements()->sole()->unit_cost);
        $this->assertSame(4, $item->refresh()->accepted_quantity);
    }

    public function test_posted_adjustment_is_immutable(): void
    {
        [$owner] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $adjustment = app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_decrease', 1), $owner);
        $this->expectException(ImmutableInventoryRecordException::class);
        $adjustment->forceFill(['reason' => 'Changed'])->save();
    }

    public function test_reserved_unfulfilled_stock_is_released_only_as_needed(): void
    {
        [$owner, , $item] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $balance = InventoryAllocationBalance::query()->sole();
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => 4,
            'reserved_by_user_id' => $owner->id,
        ]);
        InventoryAllocationReservationLine::query()->create([
            'inventory_reservation_id' => $reservation->id,
            'account_id' => $balance->account_id,
            'quantity' => 4,
            'status' => 'reserved',
        ]);
        $inventory->forceFill(['reserved_quantity' => 4])->save();
        $balance->forceFill(['reserved_quantity' => 4])->save();

        app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_decrease', 1, $item->id), $owner);

        $this->assertSame(3, $inventory->refresh()->available_quantity);
        $this->assertSame(3, $inventory->reserved_quantity);
        $this->assertSame(3, $balance->refresh()->allocated_quantity);
        $this->assertSame(3, $balance->reserved_quantity);
        $this->assertSame(3, $reservation->refresh()->quantity);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'reservation_decrease', 'quantity' => 1]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => StockMovementType::ReservationRelease->value, 'reserved_delta' => -1]);
    }

    public function test_consumed_stock_cannot_be_removed_and_transaction_rolls_back(): void
    {
        [$owner, , $item] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $inventory->forceFill(['available_quantity' => 3])->save();
        InventoryAllocationBalance::query()->sole()->forceFill(['allocated_quantity' => 3])->save();

        try {
            app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_decrease', 4, $item->id), $owner);
            $this->fail('Consumed stock was removed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('fulfilled or consumed', collect($exception->errors())->flatten()->first());
        }
        $this->assertDatabaseCount('inventory_adjustments', 0);
        $this->assertSame(3, $inventory->refresh()->available_quantity);
    }

    public function test_standalone_adjustments_and_damage_transitions_share_one_history(): void
    {
        [$owner] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $account = PurchaseReceiptAllocationLine::query()->sole()->account_id;
        $service = app(InventoryAdjustmentService::class);
        $service->post($this->data($inventory, 'saleable_increase', 1, null, false, $account), $owner);
        $service->post($this->data($inventory, 'saleable_decrease', 1), $owner);
        $service->post($this->data($inventory, 'mark_damaged', 1), $owner);
        $service->post($this->data($inventory, 'restore_damaged', 1), $owner);

        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertDatabaseCount('inventory_adjustments', 4);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'damaged_allocation_hold']);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'damaged_allocation_restore']);
    }

    public function test_standalone_increase_into_empty_balance_requires_explicit_valuation(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $inventory = ProductInventory::factory()->create([
            'available_quantity' => 0, 'reserved_quantity' => 0, 'damaged_quantity' => 0, 'average_cost' => null,
        ]);
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);
        $service = app(InventoryAdjustmentService::class);
        try {
            $service->post($this->data($inventory, 'saleable_increase', 1, null, false, $account->id), $owner);
            $this->fail('Empty inventory received stock without a valuation cost.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.valuation_unit_cost', $exception->errors());
        }

        $adjustment = $service->post(new InventoryAdjustmentData(
            $inventory->id, 'saleable_increase', 1, 'Physical count found one unit.',
            (string) Str::uuid(), null, $account->id, false, '12.0000',
        ), $owner);
        $this->assertSame(1, $inventory->refresh()->available_quantity);
        $this->assertSame('12.0000', $inventory->average_cost);
        $this->assertSame('12.0000', $adjustment->movements()->sole()->unit_cost);
    }

    public function test_new_delivery_answer_does_not_post_an_adjustment(): void
    {
        [$owner] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $this->expectException(ValidationException::class);
        app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_increase', 1, null, true), $owner);
    }

    public function test_unauthorized_staff_cannot_post(): void
    {
        $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $staff = $this->user(EmployeeRole::Staff);
        $this->expectException(AuthorizationException::class);
        app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_decrease', 1), $staff);
    }

    public function test_adjustment_override_does_not_bypass_responsibility_scope(): void
    {
        [$owner] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $staff = $this->user(EmployeeRole::Staff);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => InventoryPermission::AdjustStock->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Permission override without Product responsibility.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);

        $this->expectException(AuthorizationException::class);
        app(InventoryAdjustmentService::class)->post($this->data($inventory, 'saleable_decrease', 1), $staff);
    }

    public function test_batch_rolls_back_every_line_if_later_line_fails(): void
    {
        [$owner] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        try {
            app(InventoryAdjustmentService::class)->postBatch([
                $this->data($inventory, 'saleable_decrease', 1),
                $this->data($inventory, 'saleable_decrease', 4),
            ], $owner);
            $this->fail('Invalid batch was posted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.1.quantity', $exception->errors());
        }
        $this->assertDatabaseCount('inventory_adjustments', 0);
        $this->assertSame(4, $inventory->refresh()->available_quantity);
    }

    public function test_positive_new_delivery_choice_redirects_to_quick_purchase_without_posting(): void
    {
        [$owner] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $component = Livewire::actingAs($owner)->test(StockAdjustments::class)
            ->assertSee('Reserved');
        $component->set('data.lines', [[
            'inventory_id' => $inventory->id,
            'type' => 'saleable_increase',
            'quantity' => 1,
            'reason' => 'A new supplier delivery arrived today.',
            'is_new_purchase' => 'yes',
            'idempotency_key' => (string) Str::uuid(),
        ]])->call('saveAll')
            ->assertRedirect(QuickStockPurchase::getUrl(['product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id]));

        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertDatabaseCount('inventory_adjustments', 0);
    }

    public function test_grn_linked_page_and_quick_purchase_prefill_the_known_context(): void
    {
        [$owner, , $item] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();

        $adjustmentPage = Livewire::actingAs($owner)->withQueryParams(['receipt_item_id' => $item->id])
            ->test(StockAdjustments::class);
        $line = collect($adjustmentPage->instance()->getSchema('content')->getRawState()['lines'])->first();
        $this->assertSame($item->id, (int) $line['receipt_item_id']);
        $this->assertSame($inventory->id, (int) $line['inventory_id']);

        $purchasePage = Livewire::actingAs($owner)->withQueryParams([
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
        ])->test(QuickStockPurchase::class);
        $state = $purchasePage->instance()->getSchema('content')->getRawState();
        $this->assertSame($inventory->warehouse_id, (int) $state['warehouse_id']);
        $this->assertSame($inventory->product_id, (int) collect($state['items'])->first()['product_id']);
    }

    public function test_save_all_posts_one_atomic_batch_and_refreshes_history(): void
    {
        [$owner] = $this->receivedPurchase();
        $inventory = ProductInventory::query()->sole();
        $accountId = PurchaseReceiptAllocationLine::query()->sole()->account_id;

        Livewire::actingAs($owner)->test(StockAdjustments::class)
            ->set('data.lines', [
                [
                    'inventory_id' => $inventory->id, 'type' => 'saleable_increase', 'quantity' => 1,
                    'reason' => 'Physical count is one unit higher.', 'is_new_purchase' => 'no',
                    'allocation_account_id' => $accountId, 'idempotency_key' => (string) Str::uuid(),
                ],
                [
                    'inventory_id' => $inventory->id, 'type' => 'saleable_decrease', 'quantity' => 1,
                    'reason' => 'Another unit was missing from the count.', 'idempotency_key' => (string) Str::uuid(),
                ],
            ])
            ->call('saveAll')
            ->assertHasNoErrors()
            ->assertNotified('2 stock adjustment(s) posted');

        $this->assertDatabaseCount('inventory_adjustments', 2);
        $this->assertSame(1, InventoryAdjustment::query()->distinct()->count('batch_key'));
        $this->assertSame(4, $inventory->refresh()->available_quantity);
    }

    public function test_employee_batch_sends_one_review_notification_to_owner(): void
    {
        [$owner] = $this->receivedPurchase();
        $admin = $this->user(EmployeeRole::Admin);
        $inventory = ProductInventory::query()->sole();
        app(InventoryAdjustmentService::class)->postBatch([
            $this->data($inventory, 'saleable_decrease', 1),
            $this->data($inventory, 'saleable_decrease', 1),
        ], $admin);

        $this->assertSame(1, $owner->notifications()->where('type', 'inventory_adjustment_batch')->count());
        $this->assertDatabaseCount('inventory_adjustments', 2);
    }

    private function data(ProductInventory $inventory, string $type, int $quantity, ?int $receiptItemId = null, ?bool $newPurchase = null, ?int $accountId = null): InventoryAdjustmentData
    {
        return new InventoryAdjustmentData($inventory->id, $type, $quantity, 'Physical count discrepancy recorded by Owner.',
            (string) Str::uuid(), $receiptItemId, $accountId, $newPurchase);
    }

    private function receivedPurchase(): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create();
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            Supplier::factory()->create()->id,
            Warehouse::query()->where('code', 'MAIN')->sole()->id,
            now()->toDateString(), [new PurchaseItemData($product->id, 4, '20.0000')],
            'INV-'.Str::random(8), now()->toDateString(),
        ), $owner);
        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Owner approval.', true), $owner);
        $line = $purchase->items()->sole();
        $receipt = app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 4, 0, 0)], now()->toDateTimeString(), (string) Str::uuid(),
        ), $owner);

        return [$owner, $purchase->refresh(), $receipt->items->first()];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }
}
