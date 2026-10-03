<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Purchases\QuickStockPurchase;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\EmployeeRole;
use App\Exceptions\DuplicateActiveResponsibilityException;
use App\Models\MarketplacePlatform;
use App\Models\ProductBrand;
use App\Models\Supplier;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Purchases\PurchaseReceivingService;
use App\Services\Responsibilities\ResponsibilityScopeConflictEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class PlatformOnlySharedResponsibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_two_employees_can_share_amazon_and_noon_without_operational_conflict(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        foreach ([$f['platform'], MarketplacePlatform::factory()->create()] as $platform) {
            $first = $this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $platform->id]);
            app(CreateResponsibilityAssignment::class)->handle($first, $f['owner']);
            $second = $this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'brandId' => null, 'platformId' => $platform->id]);
            $this->assertCount(0, app(ResponsibilityScopeConflictEvaluator::class)->conflicts($second));
            app(CreateResponsibilityAssignment::class)->handle($second, $f['owner']);
        }
        $this->assertDatabaseCount('responsibility_assignments', 4);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
    }

    public function test_platform_only_and_specific_brand_category_scopes_do_not_block_either_direction(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id]), $f['owner']);
        $third = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $third->employee->id, 'brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        $this->assertDatabaseCount('responsibility_assignments', 3);
    }

    public function test_same_platform_specific_brand_category_overlap_still_blocks(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id]), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('overlapping operational responsibility');
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id]), $f['owner']);
    }

    public function test_different_brands_on_same_platform_remain_independent(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['platformId' => $f['platform']->id]), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'brandId' => ProductBrand::factory()->create()->id, 'platformId' => $f['platform']->id]), $f['owner']);
        $this->assertDatabaseCount('responsibility_assignments', 2);
    }

    public function test_same_product_platform_overlap_still_blocks(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id, 'platformId' => $f['platform']->id]), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $this->expectException(ValidationException::class);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'brandId' => null, 'productId' => $f['product']->id, 'platformId' => $f['platform']->id]), $f['owner']);
    }

    public function test_same_employee_exact_duplicate_remains_rejected(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        $this->expectException(DuplicateActiveResponsibilityException::class);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
    }

    public function test_condition_or_warehouse_makes_platform_scope_specific_not_platform_only(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        foreach ([['condition' => $f['product']->condition], ['warehouseId' => $f['inventory']->warehouse_id]] as $dimension) {
            $scope = $dimension + ['brandId' => null, 'platformId' => $f['platform']->id];
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $scope), $f['owner']);
            $conflicts = app(ResponsibilityScopeConflictEvaluator::class)->conflicts($this->assignmentData($f, overrides: $scope + ['employeeId' => $other->employee->id]));
            $this->assertTrue($conflicts->contains(fn ($conflict) => $conflict['operational']));
        }
    }

    public function test_platform_only_cannot_enable_default_receipt_ownership(): void
    {
        $f = $this->responsibilityFoundation();
        try {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id, 'assignStockByDefault' => true]), $f['owner']);
            $this->fail('Platform-only cannot own stock by default.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('requires a Brand, Category, or Product scope', $exception->getMessage());
        }
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No default stock responsibility');
        app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null);
    }

    public function test_shared_platform_access_does_not_change_quick_purchase_or_grn_holder(): void
    {
        $f = $this->responsibilityFoundation(0);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        foreach ([$f['employee']->id, $other->employee->id] as $employeeId) {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $employeeId, 'brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        }
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $holder->employee->id, 'platformId' => $f['platform']->id, 'assignStockByDefault' => true]), $f['owner']);
        app(QuickStockPurchase::class)->handle(new QuickStockPurchaseData($f['inventory']->warehouse_id, today()->toDateString(), [new PurchaseItemData($f['product']->id, 1, '100.0000')], (string) Str::uuid()), $f['owner']);
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(Supplier::factory()->create()->id, $f['inventory']->warehouse_id, today()->toDateString(), [new PurchaseItemData($f['product']->id, 2, '100.0000')], 'INV-'.Str::random(8), today()->toDateString()), $f['owner']);
        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Approved', true), $f['owner']);
        app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData([new PurchaseReceiptItemData($purchase->items()->sole()->id, 2, 0, 0)], now()->toDateTimeString(), (string) Str::uuid()), $f['owner']);
        $account = app(InventoryAllocationService::class)->employeeAccount($holder->employee->id);
        $this->assertSame(3, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(3, $account->balances()->sole()->allocated_quantity);
        $this->assertDatabaseCount('inventory_allocation_balances', 1);
        $this->assertDatabaseHas('purchase_receipt_allocation_lines', ['account_id' => $account->id, 'quantity' => 1]);
        $this->assertDatabaseHas('purchase_receipt_allocation_lines', ['account_id' => $account->id, 'quantity' => 2]);
    }
}
