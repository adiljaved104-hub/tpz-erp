<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\QuickStockPurchase;
use App\Contracts\PurchasePermissionResolver;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\Enums\EmployeeRole;
use App\Enums\PurchaseEntryType;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Exceptions\QuickStockPurchaseIdempotencyConflictException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuickStockPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_posts_one_atomic_supplierless_quick_stock_purchase(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['selling_price' => '999.00']);
        ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 8, 'reserved_quantity' => 2, 'damaged_quantity' => 2, 'average_cost' => '1500.0000',
        ]);

        $result = app(QuickStockPurchase::class)->handle($this->data($warehouse, [new PurchaseItemData($product->id, 2, '1000.0000')]), $owner);

        $purchase = $result->purchase->refresh();
        $inventory = ProductInventory::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(PurchaseEntryType::QuickStock, $purchase->entry_type);
        $this->assertSame(PurchaseStatus::FullyReceived, $purchase->status);
        $this->assertNull($purchase->supplier_id);
        $this->assertSame($owner->id, $purchase->created_by_user_id);
        $this->assertSame($owner->id, $purchase->approved_by_user_id);
        $this->assertSame(1, PurchaseReceipt::query()->count());
        $this->assertSame(10, $inventory->available_quantity);
        $this->assertSame(2, $inventory->reserved_quantity);
        $this->assertSame(2, $inventory->damaged_quantity);
        $this->assertSame('1416.6667', $inventory->average_cost);
        $this->assertDatabaseHas('purchase_receipt_items', ['accepted_quantity' => 2, 'damaged_quantity' => 0, 'rejected_quantity' => 0]);
        $this->assertDatabaseHas('purchase_items', ['vat_rate' => 0, 'line_discount_total' => 0, 'received_quantity' => 2]);
        $this->assertSame('999.00', $product->refresh()->selling_price);
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(3, ActivityLog::query()->count());
        $this->assertStringNotContainsString('1000', ActivityLog::query()->pluck('properties')->implode(' '));
    }

    public function test_admin_can_post_twenty_lines_with_exactly_one_grn(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $warehouse = Warehouse::factory()->create();
        $products = Product::factory()->count(20)->create();
        $items = $products->map(fn (Product $product): PurchaseItemData => new PurchaseItemData($product->id, 1, '25.0000'))->all();

        $result = app(QuickStockPurchase::class)->handle($this->data($warehouse, $items, Supplier::factory()->create()->id), $admin);

        $this->assertSame(20, $result->purchase->items()->count());
        $this->assertSame(1, $result->purchase->receipts()->count());
        $this->assertSame(20, StockMovement::query()->count());
        $this->assertSame(20, ProductInventory::query()->sum('available_quantity'));
    }

    public function test_identical_retry_returns_existing_result_and_changed_retry_conflicts(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $data = $this->data($warehouse, [new PurchaseItemData($product->id, 2, '50.0000')]);
        $first = app(QuickStockPurchase::class)->handle($data, $owner);
        $second = app(QuickStockPurchase::class)->handle($data, $owner);

        $this->assertTrue($second->replayed);
        $this->assertSame($first->purchase->id, $second->purchase->id);
        $this->assertSame(1, Purchase::query()->count());
        $this->assertSame(1, PurchaseReceipt::query()->count());

        $this->expectException(QuickStockPurchaseIdempotencyConflictException::class);
        app(QuickStockPurchase::class)->handle(new QuickStockPurchaseData(
            $warehouse->id, now()->toDateString(), [new PurchaseItemData($product->id, 3, '50.0000')], $data->idempotencyKey,
        ), $owner);
    }

    public function test_duplicate_product_and_nonzero_vat_are_rejected_without_partial_records(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        foreach ([
            [new PurchaseItemData($product->id, 1, '10.0000'), new PurchaseItemData($product->id, 1, '10.0000')],
            [new PurchaseItemData($product->id, 1, '10.0000', '0.00', '5.00')],
        ] as $items) {
            try {
                app(QuickStockPurchase::class)->handle($this->data($warehouse, $items), $owner);
                $this->fail('Invalid Quick Stock Purchase was accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Purchase::query()->count());
                $this->assertSame(0, PurchaseReceipt::query()->count());
                $this->assertSame(0, StockMovement::query()->count());
            }
        }
    }

    public function test_inactive_related_records_are_rejected_and_manager_and_staff_are_denied(): void
    {
        $warehouse = Warehouse::factory()->inactive()->create();
        $product = Product::factory()->create();

        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            try {
                app(QuickStockPurchase::class)->handle($this->data($warehouse, [new PurchaseItemData($product->id, 1, '10.0000')]), $this->user($role));
                $this->fail("{$role->value} was allowed.");
            } catch (AuthorizationException) {
                $this->assertSame(0, Purchase::query()->count());
            }
        }

        $this->expectException(ValidationException::class);
        app(QuickStockPurchase::class)->handle($this->data($warehouse, [new PurchaseItemData($product->id, 1, '10.0000')]), $this->user(EmployeeRole::Owner));
    }

    public function test_inactive_supplier_product_and_handler_each_leave_no_partial_records(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $activeProduct = Product::factory()->create();
        $inactiveProduct = Product::factory()->create(['status' => 'inactive']);
        $inactiveSupplier = Supplier::factory()->inactive()->create();
        $inactiveHandler = Employee::factory()->inactive()->create();
        $invalid = [
            new QuickStockPurchaseData($warehouse->id, now()->toDateString(), [new PurchaseItemData($activeProduct->id, 1, '10.0000')], (string) Str::uuid(), supplierId: $inactiveSupplier->id),
            new QuickStockPurchaseData($warehouse->id, now()->toDateString(), [new PurchaseItemData($inactiveProduct->id, 1, '10.0000')], (string) Str::uuid()),
            new QuickStockPurchaseData($warehouse->id, now()->toDateString(), [new PurchaseItemData($activeProduct->id, 1, '10.0000')], (string) Str::uuid(), handledByEmployeeId: $inactiveHandler->id),
        ];

        foreach ($invalid as $data) {
            try {
                app(QuickStockPurchase::class)->handle($data, $owner);
                $this->fail('Inactive related record was accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Purchase::query()->count());
                $this->assertSame(0, PurchaseReceipt::query()->count());
                $this->assertSame(0, StockMovement::query()->count());
            }
        }
    }

    public function test_replaceable_resolver_can_grant_quick_receive_to_an_individual_staff_user(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $this->app->bind(PurchasePermissionResolver::class, fn () => new class implements PurchasePermissionResolver
        {
            public function allows(User $user, PurchasePermission $permission, ?Purchase $purchase = null): bool
            {
                return $permission === PurchasePermission::QuickReceive;
            }
        });

        $result = app(QuickStockPurchase::class)->handle($this->data($warehouse, [new PurchaseItemData($product->id, 1, '10.0000')]), $staff);

        $this->assertSame(PurchaseStatus::FullyReceived, $result->purchase->status);
        $this->assertSame(1, ProductInventory::query()->firstOrFail()->available_quantity);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }

    /** @param array<int, PurchaseItemData> $items */
    private function data(Warehouse $warehouse, array $items, ?int $supplierId = null): QuickStockPurchaseData
    {
        return new QuickStockPurchaseData(
            warehouseId: $warehouse->id,
            purchaseDate: now()->toDateString(),
            items: $items,
            idempotencyKey: (string) Str::uuid(),
            supplierId: $supplierId,
        );
    }
}
