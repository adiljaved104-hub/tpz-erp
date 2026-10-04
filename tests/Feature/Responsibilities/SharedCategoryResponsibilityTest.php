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
use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\EmployeeRole;
use App\Enums\StockRequestPurpose;
use App\Exceptions\DuplicateActiveResponsibilityException;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment as CreatePage;
use App\Models\InventoryAllocationBalance;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Supplier;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryReadService;
use App\Services\Inventory\StockRequestService;
use App\Services\Purchases\PurchaseReceivingService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityScopeConflictEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class SharedCategoryResponsibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_two_employees_share_platform_category_without_creating_stock_ownership(): void
    {
        $f = $this->responsibilityFoundation();
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        foreach ([$f['employee']->id, $second->employee->id] as $employeeId) {
            $data = $this->assignmentData($f, overrides: $this->shared($f) + ['employeeId' => $employeeId]);
            $this->assertCount(0, app(ResponsibilityScopeConflictEvaluator::class)->conflicts($data));
            $assignment = app(CreateResponsibilityAssignment::class)->handle($data, $f['owner']);
            $this->assertFalse($assignment->assign_stock_by_default);
        }
        $this->assertDatabaseCount('responsibility_assignments', 2);
        $this->assertSame(0, DB::table('inventory_allocation_accounts')->where('is_system', false)->count());
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
        $this->assertSame(20, $f['inventory']->refresh()->available_quantity);
    }

    public function test_shared_and_specific_assignments_coexist_in_both_creation_directions(): void
    {
        $f = $this->responsibilityFoundation();
        $create = app(CreateResponsibilityAssignment::class);
        $create->handle($this->assignmentData($f, overrides: $this->shared($f)), $f['owner']);
        $specific = $this->responsibilityUser(EmployeeRole::Staff);
        $create->handle($this->assignmentData($f, overrides: [
            'employeeId' => $specific->employee->id, 'categoryId' => $f['product']->category_id,
            'platformId' => $f['platform']->id, 'assignStockByDefault' => true,
        ]), $f['owner']);
        $third = $this->responsibilityUser(EmployeeRole::Staff);
        $create->handle($this->assignmentData($f, overrides: $this->shared($f) + ['employeeId' => $third->employee->id]), $f['owner']);
        $this->assertDatabaseCount('responsibility_assignments', 3);
        [$account] = app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null);
        $this->assertSame($specific->employee->id, $account->employee_id);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
    }

    public function test_same_specific_brand_category_platform_remains_exclusive(): void
    {
        $f = $this->responsibilityFoundation();
        $scope = ['categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id];
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $scope), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('overlapping operational responsibility');
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $scope + ['employeeId' => $other->employee->id]), $f['owner']);
    }

    public function test_different_brands_categories_and_platforms_remain_independent(): void
    {
        $f = $this->responsibilityFoundation();
        $scope = ['categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id];
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $scope), $f['owner']);
        foreach ([
            ['brandId' => ProductBrand::factory()->create()->id],
            ['categoryId' => ProductCategory::factory()->create()->id],
            ['platformId' => MarketplacePlatform::factory()->create()->id],
        ] as $difference) {
            $other = $this->responsibilityUser(EmployeeRole::Staff);
            $data = $this->assignmentData($f, overrides: $difference + $scope + ['employeeId' => $other->employee->id]);
            $this->assertCount(0, app(ResponsibilityScopeConflictEvaluator::class)->conflicts($data));
            app(CreateResponsibilityAssignment::class)->handle($data, $f['owner']);
        }
        $this->assertDatabaseCount('responsibility_assignments', 4);
    }

    public function test_specific_brand_and_product_overlap_is_protected_in_both_directions(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $data = $this->assignmentData($f, overrides: [
            'employeeId' => $other->employee->id, 'brandId' => null,
            'productId' => $f['product']->id, 'platformId' => $f['platform']->id,
        ]);
        $this->assertTrue(app(ResponsibilityScopeConflictEvaluator::class)->conflicts($data)->contains(fn ($conflict) => $conflict['operational']));
        try {
            app(CreateResponsibilityAssignment::class)->handle($data, $f['owner']);
            $this->fail('Product within an exclusive Brand scope must conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope', $exception->errors());
        }
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $f['product']->category_id]);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'productId' => $product->id, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $data = $this->assignmentData($f, overrides: [
            'employeeId' => $other->employee->id, 'brandId' => $brand->id,
            'categoryId' => $product->category_id, 'platformId' => $f['platform']->id,
        ]);
        $this->assertTrue(app(ResponsibilityScopeConflictEvaluator::class)->conflicts($data)->contains(fn ($conflict) => $conflict['operational']));
        $this->assertDatabaseCount('responsibility_assignments', 2);
    }

    public function test_shared_category_default_stock_is_rejected_on_create_and_toggle(): void
    {
        $f = $this->responsibilityFoundation();
        try {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $this->shared($f) + ['assignStockByDefault' => true]), $f['owner']);
            $this->fail('Shared category cannot become a receipt owner.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assign_stock_by_default', $exception->errors());
        }
        $assignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $this->shared($f)), $f['owner']);
        try {
            app(ResponsibilityAssignmentService::class)->setStockDefault($assignment, true, 'Attempt shared ownership', $f['owner']);
            $this->fail('Shared default toggle must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assign_stock_by_default', $exception->errors());
        }
        $this->assertFalse($assignment->refresh()->assign_stock_by_default);
        $this->assertSame(0, $assignment->successors()->count());
    }

    public function test_receipt_resolution_ignores_legacy_shared_default_flags_without_rewriting_them(): void
    {
        $f = $this->responsibilityFoundation();
        $shared = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $this->shared($f)), $f['owner']);
        DB::table('responsibility_assignments')->where('id', $shared->id)->update(['assign_stock_by_default' => true]);
        $this->assertFalse(app(ResponsibilityScopeConflictEvaluator::class)->matchesDefaultStockForInventory($shared->refresh(), $f['inventory']));
        try {
            app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null);
            $this->fail('Legacy shared flag must not determine receipt ownership.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertTrue($shared->refresh()->assign_stock_by_default);
        $this->assertSame(0, DB::table('inventory_allocation_accounts')->where('is_system', false)->count());
        $specific = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $specific->employee->id, 'categoryId' => $f['product']->category_id,
            'platformId' => $f['platform']->id, 'assignStockByDefault' => true,
        ]), $f['owner']);
        [$account] = app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null);
        $this->assertSame($specific->employee->id, $account->employee_id);
    }

    public function test_shared_category_form_clears_and_hides_default_stock_selection(): void
    {
        $f = $this->responsibilityFoundation();
        $this->actingAs($f['owner']);
        Livewire::test(CreatePage::class)
            ->fillForm(['scope_type' => 'brand', 'assign_stock_by_default' => true])
            ->set('data.scope_type', 'category_platform')
            ->assertSet('data.assign_stock_by_default', false)
            ->assertFormFieldIsHidden('assign_stock_by_default');
    }

    public function test_same_employee_shared_category_duplicate_remains_rejected(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $this->shared($f)), $f['owner']);
        $this->expectException(DuplicateActiveResponsibilityException::class);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $this->shared($f)), $f['owner']);
    }

    public function test_grn_and_quick_receipts_keep_the_specific_holder_despite_shared_category_users(): void
    {
        $f = $this->responsibilityFoundation(0);
        foreach ([$f['employee']->id, $this->responsibilityUser(EmployeeRole::Staff)->employee->id] as $employeeId) {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $this->shared($f) + ['employeeId' => $employeeId]), $f['owner']);
        }
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $holder->employee->id, 'categoryId' => $f['product']->category_id,
            'platformId' => $f['platform']->id, 'assignStockByDefault' => true,
        ]), $f['owner']);
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

    public function test_shared_category_visibility_and_requests_preserve_real_holder_and_scope(): void
    {
        $f = $this->responsibilityFoundation(5);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $this->shared($f)), $f['owner']);
        $staff = $f['employee']->user;
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $holder->employee->id, 'categoryId' => $f['product']->category_id,
            'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $account = $allocations->employeeAccount($holder->employee->id);
        $allocations->reconcile($f['inventory'], $account, 5, $f['owner'], 'Existing actual holder');
        $before = InventoryAllocationBalance::query()->orderBy('id')->get()->toArray();
        $outside = ProductInventory::factory()->create(['product_id' => Product::factory()->create(['category_id' => ProductCategory::factory()->create()->id])->id]);
        $visible = app(InventoryReadService::class)->inventories($staff)->pluck('product_inventories.id');
        $this->assertContains($f['inventory']->id, $visible);
        $this->assertNotContains($outside->id, $visible);
        $this->assertDatabaseCount('product_marketplace_listings', 0);
        $requests = app(StockRequestService::class);
        $this->assertTrue($requests->searchInventories($staff, $f['product']->sku)->contains('id', $f['inventory']->id));
        $availability = $requests->sourceAvailability($f['inventory']->id);
        $this->assertSame($account->id, $availability['holders']->sole()['account_id']);
        $request = $requests->create(new CreateStockRequestData(StockRequestPurpose::PermanentTransfer, null, [new StockRequestItemData($f['inventory']->id, 2)], 'Request stock from its actual holder', (string) Str::uuid()), $staff);
        $this->assertSame($account->id, $request->items->sole()->proposed_sources[0]['account_id']);
        $this->assertSame($before, InventoryAllocationBalance::query()->orderBy('id')->get()->toArray());
        $this->assertSame(5, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(0, $f['inventory']->reserved_quantity);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertFalse($allocations->canConsumeFromAccount($staff, $account));
    }

    private function shared(array $f): array
    {
        return ['brandId' => null, 'categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id];
    }
}
