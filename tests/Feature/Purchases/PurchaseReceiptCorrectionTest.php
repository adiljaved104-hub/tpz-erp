<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Exceptions\ImmutablePurchaseException;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptAllocationLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Purchases\PurchaseReceiptCorrectionService;
use App\Services\Purchases\PurchaseReceivingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_owner_posts_immutable_physical_allocation_cost_and_purchase_correction_once(): void
    {
        [$owner, $purchase, $receiptItem] = $this->receivedPurchase(4, '100.0000');
        $inventory = ProductInventory::query()->sole();
        $originalAllocation = PurchaseReceiptAllocationLine::query()->sole();
        $key = (string) Str::uuid();

        $first = app(PurchaseReceiptCorrectionService::class)->correct($receiptItem, 3, 'Original GRN quantity entered incorrectly.', $key, $owner);
        $second = app(PurchaseReceiptCorrectionService::class)->correct($receiptItem, 3, 'Retry.', $key, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(4, $receiptItem->refresh()->accepted_quantity);
        $this->assertSame(3, $inventory->refresh()->available_quantity);
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertSame(3, InventoryAllocationBalance::query()->where('account_id', $originalAllocation->account_id)->sole()->allocated_quantity);
        $this->assertSame(3, $purchase->items()->sole()->received_quantity);
        $this->assertSame(PurchaseStatus::PartiallyReceived, $purchase->refresh()->status);
        $this->assertDatabaseCount('purchase_receipt_corrections', 1);
        $this->assertDatabaseCount('purchase_receipt_correction_allocation_lines', 1);
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovementType::PurchaseReceiptCorrection->value,
            'quantity' => 1,
            'available_delta' => -1,
            'source_id' => $first->id,
        ]);
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'purchase_receipt.corrected', 'subject_id' => $first->id]);
        $this->assertNotNull(ActivityLog::query()->where('event', 'purchase_receipt.corrected')->sole()->properties);

        $this->expectException(ImmutablePurchaseException::class);
        $first->forceFill(['reason' => 'changed'])->save();
    }

    public function test_permission_defaults_and_admin_override_are_enforced_server_side(): void
    {
        [$owner, $purchase, $receiptItem] = $this->receivedPurchase(4, '20.0000');
        foreach ([EmployeeRole::Admin, EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $user = $this->user($role);
            $this->assertFalse(app(PurchaseAuthorization::class)->allows($user, PurchasePermission::CorrectReceipt, $purchase));
        }
        $this->assertTrue(app(PurchaseAuthorization::class)->allows($owner, PurchasePermission::CorrectReceipt, $purchase));

        $admin = $this->user(EmployeeRole::Admin);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => PurchasePermission::CorrectReceipt->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Authorized GRN correction test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);

        $correction = app(PurchaseReceiptCorrectionService::class)->correct(
            $receiptItem,
            3,
            'Authorized correction by employee override.',
            (string) Str::uuid(),
            $admin,
        );
        $this->assertSame($admin->id, $correction->performed_by_user_id);

        $staff = $this->user(EmployeeRole::Staff);
        $this->expectException(AuthorizationException::class);
        app(PurchaseReceiptCorrectionService::class)->correct($receiptItem, 2, 'Not authorized.', (string) Str::uuid(), $staff);
    }

    public function test_split_original_allocation_is_reversed_deterministically_without_touching_other_stock(): void
    {
        [$owner, , $receiptItem] = $this->receivedPurchase(4, '20.0000');
        $inventory = ProductInventory::query()->sole();
        $firstLine = PurchaseReceiptAllocationLine::query()->sole();
        $firstBalance = InventoryAllocationBalance::query()->where('account_id', $firstLine->account_id)->sole();
        $employee = Employee::factory()->role(EmployeeRole::Staff)->create();
        $secondAccount = app(InventoryAllocationService::class)->employeeAccount($employee->id);
        $otherAccount = app(InventoryAllocationService::class)->employeeAccount(Employee::factory()->role(EmployeeRole::Staff)->create()->id);

        $firstLine->forceFill(['quantity' => 2])->save();
        $firstBalance->forceFill(['allocated_quantity' => 2])->save();
        $secondBalance = InventoryAllocationBalance::query()->create([
            'account_id' => $secondAccount->id, 'product_inventory_id' => $inventory->id,
            'allocated_quantity' => 2, 'reserved_quantity' => 0,
        ]);
        $otherBalance = InventoryAllocationBalance::query()->create([
            'account_id' => $otherAccount->id, 'product_inventory_id' => $inventory->id,
            'allocated_quantity' => 2, 'reserved_quantity' => 0,
        ]);
        PurchaseReceiptAllocationLine::query()->create([
            'purchase_receipt_item_id' => $receiptItem->id,
            'account_id' => $secondAccount->id,
            'quantity' => 2,
            'allocation_method' => 'explicit',
        ]);

        $correction = app(PurchaseReceiptCorrectionService::class)->correct(
            $receiptItem, 1, 'Split allocation correction.', (string) Str::uuid(), $owner,
        );

        $this->assertSame(0, $firstBalance->refresh()->allocated_quantity);
        $this->assertSame(1, $secondBalance->refresh()->allocated_quantity);
        $this->assertSame(2, $otherBalance->refresh()->allocated_quantity);
        $this->assertSame([2, 1], $correction->allocationLines()->orderBy('id')->pluck('quantity')->all());
    }

    public function test_unsafe_or_upward_correction_rolls_back_without_negative_stock(): void
    {
        [$owner, , $receiptItem] = $this->receivedPurchase(4, '20.0000');
        $inventory = ProductInventory::query()->sole();
        $balance = InventoryAllocationBalance::query()->sole();
        $inventory->forceFill(['reserved_quantity' => 4])->save();
        $balance->forceFill(['reserved_quantity' => 4])->save();

        try {
            app(PurchaseReceiptCorrectionService::class)->correct(
                $receiptItem, 3, 'Unsafe correction.', (string) Str::uuid(), $owner,
            );
            $this->fail('Unsafe correction was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('reserved or consumed', $exception->errors()['corrected_quantity'][0]);
        }
        $this->assertDatabaseCount('purchase_receipt_corrections', 0);
        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertSame(4, $balance->refresh()->allocated_quantity);

        $inventory->forceFill(['reserved_quantity' => 0])->save();
        $balance->forceFill(['reserved_quantity' => 0])->save();
        try {
            app(PurchaseReceiptCorrectionService::class)->correct(
                $receiptItem, 5, 'Upward correction.', (string) Str::uuid(), $owner,
            );
            $this->fail('Upward correction was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('normal Purchase Receiving', $exception->errors()['corrected_quantity'][0]);
        }
        $this->assertDatabaseCount('purchase_receipt_corrections', 0);
    }

    public function test_correction_history_and_action_render_on_grn_view(): void
    {
        [$owner, , $receiptItem] = $this->receivedPurchase(4, '20.0000');
        app(PurchaseReceiptCorrectionService::class)->correct(
            $receiptItem, 3, 'History rendering correction.', (string) Str::uuid(), $owner,
        );
        $this->actingAs($owner);

        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receiptItem->purchase_receipt_id])
            ->assertOk()
            ->assertActionVisible('correctReceivedQuantity')
            ->assertSee('Correction History')
            ->assertSee('History rendering correction.')
            ->assertSee('GRC-');
    }

    public function test_owner_submits_correction_action_and_sees_refreshed_effective_quantities_and_history(): void
    {
        [$owner, $purchase, $receiptItem] = $this->receivedPurchase(4, '20.0000');

        $component = Livewire::actingAs($owner)
            ->test(ViewPurchaseReceipt::class, ['record' => $receiptItem->purchase_receipt_id])
            ->assertActionVisible('correctReceivedQuantity')
            ->callAction('correctReceivedQuantity', [
                'receipt_item_id' => $receiptItem->id,
                'corrected_quantity' => 3,
                'reason' => 'Quantity was entered incorrectly on the posted GRN.',
            ])
            ->assertHasNoErrors()
            ->assertNotified('GRN correction recorded')
            ->assertSee('Correction History')
            ->assertSee('Original Received')
            ->assertSee('Prior Corrections')
            ->assertSee('Effective Received')
            ->assertSee('GRC-');

        $correction = $receiptItem->corrections()->sole();
        $component->assertSee($correction->reference);
        $this->assertSame(4, $receiptItem->refresh()->accepted_quantity);
        $this->assertSame(-1, $correction->adjustment_quantity);
        $this->assertSame(3, ProductInventory::query()->sole()->available_quantity);
        $this->assertSame(3, $purchase->items()->sole()->received_quantity);
    }

    public function test_rejected_correction_action_shows_error_and_does_not_write(): void
    {
        [$owner, , $receiptItem] = $this->receivedPurchase(4, '20.0000');

        Livewire::actingAs($owner)
            ->test(ViewPurchaseReceipt::class, ['record' => $receiptItem->purchase_receipt_id])
            ->callAction('correctReceivedQuantity', [
                'receipt_item_id' => $receiptItem->id,
                'corrected_quantity' => 4,
                'reason' => 'There is no actual quantity change.',
            ])
            ->assertHasActionErrors(['corrected_quantity'])
            ->assertNotified('GRN correction was not recorded');

        $this->assertDatabaseCount('purchase_receipt_corrections', 0);
        $this->assertSame(4, $receiptItem->refresh()->accepted_quantity);
        $this->assertSame(4, ProductInventory::query()->sole()->available_quantity);
    }

    public function test_unauthorized_employee_cannot_use_correction_action(): void
    {
        [, , $receiptItem] = $this->receivedPurchase(4, '20.0000');
        $staff = $this->user(EmployeeRole::Staff);

        Livewire::actingAs($staff)
            ->test(ViewPurchaseReceipt::class, ['record' => $receiptItem->purchase_receipt_id])
            ->assertActionHidden('correctReceivedQuantity');

        $this->assertDatabaseCount('purchase_receipt_corrections', 0);
    }

    private function receivedPurchase(int $quantity, string $cost): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create();
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            Supplier::factory()->create()->id,
            Warehouse::query()->where('code', 'MAIN')->sole()->id,
            now()->toDateString(),
            [new PurchaseItemData($product->id, $quantity, $cost)],
            'INV-'.Str::random(8),
            now()->toDateString(),
        ), $owner);
        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Owner approval.', true), $owner);
        $purchase->load('items');
        $line = $purchase->items->first();
        $receipt = app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, $quantity, 0, 0)],
            now()->toDateTimeString(),
            (string) Str::uuid(),
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
