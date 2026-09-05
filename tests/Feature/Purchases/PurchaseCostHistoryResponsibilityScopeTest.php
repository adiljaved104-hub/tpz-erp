<?php

namespace Tests\Feature\Purchases;

use App\Contracts\PurchasePermissionResolver;
use App\DTOs\Purchases\PurchaseCostHistoryFilterData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchases\PurchaseCostHistoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseCostHistoryResponsibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_with_permission_see_all_cost_history_rows(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $this->receipt($first, $owner, '10.0000');
        $this->receipt($second, $owner, '20.0000');

        $this->assertCount(2, app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $owner)->get());
        $this->assertCount(2, app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $admin)->get());
    }

    public function test_staff_and_manager_without_permission_are_denied_before_history_is_queried(): void
    {
        $this->denyCostHistoryPermission();

        foreach ([EmployeeRole::Staff, EmployeeRole::Manager] as $role) {
            $user = $this->user($role);
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $user)->get();
                $this->fail("{$role->value} without permission must be denied.");
            } catch (AuthorizationException) {
                $historyQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'purchase_receipt_items'));
                $this->assertCount(0, $historyQueries);
            }

            $this->actingAs($user)->get('/admin/product-purchase-cost-history-report')->assertForbidden();
        }
    }

    public function test_staff_with_active_brand_scope_sees_only_that_brands_products_and_suppliers(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->grantCostHistoryPermission($staff, $owner);
        $lenovo = ProductBrand::factory()->create(['name' => 'Lenovo', 'normalized_name' => 'lenovo']);
        $hp = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $lenovoProduct = Product::factory()->create(['brand_id' => $lenovo->id, 'brand' => 'Lenovo']);
        $hpProduct = Product::factory()->create(['brand_id' => $hp->id, 'brand' => 'HP']);
        $lenovoSupplier = Supplier::factory()->create(['name' => 'Lenovo Supplier']);
        $hpSupplier = Supplier::factory()->create(['name' => 'HP Supplier']);
        $this->receipt($lenovoProduct, $owner, '100.0000', $lenovoSupplier);
        $this->receipt($hpProduct, $owner, '200.0000', $hpSupplier);
        $this->assignBrand($staff, $lenovo, $owner);

        $rows = app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $staff)->get();
        $suppliers = app(PurchaseCostHistoryService::class)->supplierOptions($staff);

        $this->assertSame([$lenovoProduct->sku], $rows->pluck('sku')->all());
        $this->assertSame([$lenovoSupplier->name], array_values($suppliers));
        $this->assertNotContains($hpProduct->sku, $rows->pluck('sku')->all());
    }

    public function test_brand_plus_platform_scope_remains_product_scoped_and_does_not_expose_other_brands(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->grantCostHistoryPermission($staff, $owner);
        $lenovo = ProductBrand::factory()->create(['name' => 'Lenovo', 'normalized_name' => 'lenovo']);
        $hp = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $lenovoProduct = Product::factory()->create(['brand_id' => $lenovo->id, 'brand' => 'Lenovo']);
        $hpProduct = Product::factory()->create(['brand_id' => $hp->id, 'brand' => 'HP']);
        $this->receipt($lenovoProduct, $owner, '100.0000');
        $this->receipt($hpProduct, $owner, '200.0000');
        $assignment = $this->assignBrand($staff, $lenovo, $owner);
        $platform = MarketplacePlatform::factory()->create();
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $platform->id]);

        $rows = app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $staff)->get();

        $this->assertSame([$lenovoProduct->sku], $rows->pluck('sku')->all());
        $this->assertNotContains($hpProduct->sku, $rows->pluck('sku')->all());
    }

    public function test_no_active_product_responsibility_returns_zero_rows(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $owner = $this->user(EmployeeRole::Owner);
        $this->grantCostHistoryPermission($staff, $owner);
        $product = Product::factory()->create();
        $this->receipt($product, $owner, '100.0000');

        $this->assertCount(0, app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $staff)->get());
    }

    public function test_inactive_transferred_and_superseded_assignments_grant_no_rows(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $owner = $this->user(EmployeeRole::Owner);
        $this->grantCostHistoryPermission($staff, $owner);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $this->receipt($product, $owner, '100.0000');

        foreach ([ResponsibilityAssignmentStatus::Inactive, ResponsibilityAssignmentStatus::Transferred, ResponsibilityAssignmentStatus::Superseded] as $status) {
            $this->assignBrand($staff, $brand, $owner, $status);
        }

        $this->assertCount(0, app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $staff)->get());
    }

    public function test_overlapping_active_scopes_do_not_duplicate_purchase_history_rows(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $owner = $this->user(EmployeeRole::Owner);
        $this->grantCostHistoryPermission($staff, $owner);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $this->receipt($product, $owner, '100.0000');
        $this->assignBrand($staff, $brand, $owner);
        $direct = $this->assignment($staff, $owner);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $direct->id, 'product_id' => $product->id]);

        $rows = app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $staff)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($product->sku, $rows->first()->sku);
    }

    public function test_direct_product_and_quantity_scopes_grant_only_their_products(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $owner = $this->user(EmployeeRole::Owner);
        $directProduct = Product::factory()->create();
        $quantityProduct = Product::factory()->create();
        $unrelated = Product::factory()->create();
        $this->receipt($directProduct, $owner, '10.0000');
        $this->receipt($quantityProduct, $owner, '20.0000');
        $this->receipt($unrelated, $owner, '30.0000');
        $direct = $this->assignment($manager, $owner);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $direct->id, 'product_id' => $directProduct->id]);
        $platform = MarketplacePlatform::factory()->create();
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $direct->id, 'marketplace_platform_id' => $platform->id]);
        $inventory = ProductInventory::factory()->create(['product_id' => $quantityProduct->id]);
        $quantity = $this->assignment($manager, $owner);
        DB::table('inventory_responsibility_quantities')->insert(['assignment_id' => $quantity->id, 'product_inventory_id' => $inventory->id, 'assigned_quantity' => 1]);
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $quantity->id, 'marketplace_platform_id' => $platform->id]);

        $rows = app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $manager)->get();

        $this->assertEqualsCanonicalizing([$directProduct->sku, $quantityProduct->sku], $rows->pluck('sku')->all());
        $this->assertNotContains($unrelated->sku, $rows->pluck('sku')->all());
    }

    public function test_platform_only_scope_fails_closed_without_a_product_platform_mapping(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $owner = $this->user(EmployeeRole::Owner);
        $this->grantCostHistoryPermission($staff, $owner);
        $product = Product::factory()->create();
        $this->receipt($product, $owner, '100.0000');
        $assignment = $this->assignment($staff, $owner);
        $platform = MarketplacePlatform::factory()->create();
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $platform->id]);

        $rows = app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData, $staff)->get();

        $this->assertCount(0, $rows);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function assignment(User $employeeUser, User $actor, ResponsibilityAssignmentStatus $status = ResponsibilityAssignmentStatus::Active): ResponsibilityAssignment
    {
        return ResponsibilityAssignment::factory()->create([
            'employee_id' => $employeeUser->employee->id,
            'assigned_by_user_id' => $actor->id,
            'status' => $status,
            'active_fingerprint' => $status === ResponsibilityAssignmentStatus::Active ? hash('sha256', (string) Str::uuid()) : null,
            'ended_at' => $status === ResponsibilityAssignmentStatus::Active ? null : now(),
        ]);
    }

    private function assignBrand(User $employeeUser, ProductBrand $brand, User $actor, ResponsibilityAssignmentStatus $status = ResponsibilityAssignmentStatus::Active): ResponsibilityAssignment
    {
        $assignment = $this->assignment($employeeUser, $actor, $status);
        ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $brand->id]);

        return $assignment;
    }

    private function receipt(Product $product, User $receiver, string $cost, ?Supplier $supplier = null): PurchaseReceiptItem
    {
        $purchase = Purchase::factory()->create(['status' => PurchaseStatus::FullyReceived, 'supplier_id' => $supplier?->id]);
        $item = PurchaseItem::factory()->create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'unit_cost' => $cost, 'inventory_unit_cost' => $cost]);
        $receipt = PurchaseReceipt::factory()->create(['purchase_id' => $purchase->id, 'warehouse_id' => $purchase->warehouse_id, 'received_by_user_id' => $receiver->id]);

        return PurchaseReceiptItem::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'purchase_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity_received' => 1,
            'accepted_quantity' => 1,
            'damaged_quantity' => 0,
            'rejected_quantity' => 0,
            'inventory_unit_cost' => $cost,
            'posting_key' => (string) Str::uuid(),
        ]);
    }

    private function denyCostHistoryPermission(): void
    {
        $this->app->bind(PurchasePermissionResolver::class, fn (): PurchasePermissionResolver => new class implements PurchasePermissionResolver
        {
            public function allows(User $user, PurchasePermission $permission, ?Purchase $purchase = null): bool
            {
                return $permission !== PurchasePermission::ViewCostHistory;
            }
        });
    }

    private function grantCostHistoryPermission(User $employeeUser, User $actor): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $employeeUser->employee->id,
            'permission_key' => PurchasePermission::ViewCostHistory->value,
            'effect' => EmployeePermissionEffect::Allow,
            'reason' => 'Advanced purchase-cost access required for this test.',
            'granted_by_user_id' => $actor->id,
        ]);
    }
}
