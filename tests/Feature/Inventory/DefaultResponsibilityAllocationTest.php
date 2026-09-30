<?php

namespace Tests\Feature\Inventory;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Purchases\QuickStockPurchase;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\InventoryAllocationBalance;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductMarketplaceListing;
use App\Models\PurchaseReceipt;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Purchases\PurchaseReceivingService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DefaultResponsibilityAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_and_quick_receipts_use_matching_brand_platform_holder_not_entering_actor(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $holder = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        ProductMarketplaceListing::query()->create(['product_id' => $product->id, 'marketplace_platform_id' => $platform->id, 'listing_title' => $product->name]);
        $this->assignment($holder, $product, $platform);

        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            Supplier::factory()->create()->id, $warehouse->id, now()->toDateString(),
            [new PurchaseItemData($product->id, 2, '100.0000')], 'INV-'.Str::random(8), now()->toDateString(),
        ), $owner);
        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Approved.', true), $owner);
        app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($purchase->items()->firstOrFail()->id, 2, 0, 0)], now()->toDateTimeString(), (string) Str::uuid(),
        ), $owner);
        app(QuickStockPurchase::class)->handle(new QuickStockPurchaseData(
            $warehouse->id, now()->toDateString(), [new PurchaseItemData($product->id, 3, '110.0000')], (string) Str::uuid(),
            handledByEmployeeId: $owner->employee->id,
        ), $owner);

        $inventory = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sole();
        $account = app(InventoryAllocationService::class)->employeeAccount($holder->employee->id);
        $this->assertSame(5, (int) InventoryAllocationBalance::query()->where('account_id', $account->id)->where('product_inventory_id', $inventory->id)->value('allocated_quantity'));
        $this->assertSame(5, $inventory->available_quantity);
        $this->assertDatabaseCount('purchase_receipt_allocation_lines', 2);
        $this->assertSame(['responsibility_default'], PurchaseReceipt::query()->with('items.allocationLines')->get()->flatMap(fn ($receipt) => $receipt->items->flatMap(fn ($item) => $item->allocationLines->pluck('allocation_method')))->unique()->all());
    }

    public function test_missing_and_conflicting_defaults_block_receipt_without_inventory_posting(): void
    {
        $this->assertTrue(Schema::hasColumn('responsibility_assignments', 'assign_stock_by_default'), app()->databasePath());
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        try {
            $this->quick($owner, $warehouse, $product);
            $this->fail('Missing default was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('No default stock responsibility', $exception->getMessage());
        }
        $this->assertDatabaseCount('product_inventories', 0);

        $this->assignment($this->user(EmployeeRole::Manager), $product);
        $this->assignment($this->user(EmployeeRole::Manager), $product);
        try {
            $this->quick($owner, $warehouse, $product);
            $this->fail('Conflicting defaults were accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Multiple default stock responsibilities', $exception->getMessage());
        }
        $this->assertDatabaseCount('product_inventories', 0);
    }

    public function test_platform_only_and_inactive_assignments_do_not_own_receipts(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        ProductMarketplaceListing::query()->create(['product_id' => $product->id, 'marketplace_platform_id' => $platform->id, 'listing_title' => $product->name]);
        $platformOnly = ResponsibilityAssignment::factory()->create(['employee_id' => $this->user(EmployeeRole::Manager)->employee->id, 'assign_stock_by_default' => true]);
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $platformOnly->id, 'marketplace_platform_id' => $platform->id]);
        $inactive = $this->assignment($this->user(EmployeeRole::Manager), $product, $platform);
        $inactive->forceFill(['status' => 'inactive', 'ended_at' => now(), 'active_fingerprint' => null])->save();

        $this->expectException(ValidationException::class);
        $this->quick($owner, $warehouse, $product);
    }

    public function test_editing_default_changes_future_receipts_without_moving_existing_stock(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $first = $this->user(EmployeeRole::Manager);
        $second = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $firstAssignment = $this->assignment($first, $product);
        $secondAssignment = $this->assignment($second, $product);
        $secondAssignment->forceFill(['assign_stock_by_default' => false])->save();

        $this->quick($owner, $warehouse, $product);
        $assignments = app(ResponsibilityAssignmentService::class);
        $assignments->setStockDefault($firstAssignment, false, 'Ownership changed', $owner);
        $assignments->setStockDefault($secondAssignment, true, 'Ownership changed', $owner);
        $this->quick($owner, $warehouse, $product);

        $inventory = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sole();
        $allocations = app(InventoryAllocationService::class);
        $firstAccount = $allocations->employeeAccount($first->employee->id);
        $secondAccount = $allocations->employeeAccount($second->employee->id);
        $this->assertSame(1, (int) InventoryAllocationBalance::query()->where('account_id', $firstAccount->id)->where('product_inventory_id', $inventory->id)->value('allocated_quantity'));
        $this->assertSame(1, (int) InventoryAllocationBalance::query()->where('account_id', $secondAccount->id)->where('product_inventory_id', $inventory->id)->value('allocated_quantity'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'responsibility.stock_default_changed']);
    }

    private function assignment(User $holder, Product $product, ?MarketplacePlatform $platform = null): ResponsibilityAssignment
    {
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $holder->employee->id, 'assign_stock_by_default' => true,
        ]);
        ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $product->brand_id]);
        if ($platform !== null) {
            ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $platform->id]);
        }

        return $assignment;
    }

    private function quick(User $actor, Warehouse $warehouse, Product $product): void
    {
        app(QuickStockPurchase::class)->handle(new QuickStockPurchaseData(
            $warehouse->id, now()->toDateString(), [new PurchaseItemData($product->id, 1, '100.0000')], (string) Str::uuid(),
        ), $actor);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }
}
