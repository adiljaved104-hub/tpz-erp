<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\QuickStockPurchase as PostQuickStockPurchase;
use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ProductMatchContext;
use App\Enums\PurchasePermission;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ProductIntelligence\ProductMatchService;
use App\Services\Purchases\PurchaseDocumentService;
use App\Services\Purchases\PurchaseProductContextService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class QuickStockPurchaseResponsibilityPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public static function scopeCases(): array
    {
        return array_map(fn (string $scope): array => [$scope], ['brand', 'category', 'condition', 'product', 'warehouse', 'combined']);
    }

    public static function employeeRoles(): array
    {
        return [[EmployeeRole::Staff], [EmployeeRole::Manager]];
    }

    public static function receivingWarehouseCases(): array
    {
        return [
            'Staff Main Warehouse' => [EmployeeRole::Staff, false],
            'Manager Main Warehouse' => [EmployeeRole::Manager, false],
            'Staff different platform warehouse' => [EmployeeRole::Staff, true],
            'Manager different platform warehouse' => [EmployeeRole::Manager, true],
        ];
    }

    #[DataProvider('receivingWarehouseCases')]
    public function test_asus_laptop_new_platform_responsibility_can_receive_into_physical_warehouse(EmployeeRole $role, bool $differentPlatform): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation($role);
        $brand = ProductBrand::factory()->create(['name' => 'Asus', 'normalized_name' => 'asus']);
        $category = ProductCategory::factory()->create(['name' => 'Laptops']);
        $product->update(['brand_id' => $brand->id, 'brand' => 'Asus', 'category_id' => $category->id,
            'condition' => ProductCondition::New, 'model' => 'CX1405CTA',
            'name' => 'testASUS - CX1405CTA 14" FHD Chromebook Laptop - Intel N50 (2023) - 4GB Memory - 64GB Storage - Dusk Gray (199291342897)']);
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE', 'normalized_name' => 'amazon uae']);
        $warehouse->update(['marketplace_platform_id' => $differentPlatform ? MarketplacePlatform::factory()->create()->id : null]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id, 'assign_stock_by_default' => true]);
        $assignment->brandScope()->create(['product_brand_id' => $brand->id]);
        $assignment->categoryScope()->create(['product_category_id' => $category->id]);
        $assignment->conditionScope()->create(['product_condition' => ProductCondition::New->value]);
        $assignment->platformScope()->create(['marketplace_platform_id' => $platform->id]);

        $this->actingAs($staff);
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $this->assertArrayHasKey($product->id, $this->productField($component)->getSearchResults('CX1405CT'));
        $this->assertSame($product->id, app(ProductMatchService::class)->match(new ProductMatchRequest('CX1405CT', ProductMatchContext::Receiving,
            $staff, warehouseId: $warehouse->id))->sole()->productId);

        $component->mountAction(TestAction::make('bulkAddProducts')->schemaComponent(true, 'content'));
        $bulk = collect($component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_ids');
        $this->assertArrayHasKey($product->id, $bulk->getSearchResults('CX1405CT'));
        $component->setActionData(['product_ids' => [$product->id]])->callMountedAction()->assertHasNoActionErrors();
        $this->assertContains($product->id, array_map('intval', array_column($component->instance()->data['items'], 'product_id')));
        $preselected = Livewire::withQueryParams(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->test(QuickStockPurchase::class);
        $this->assertSame($product->id, (int) collect($preselected->instance()->data['items'])->first()['product_id']);

        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 9, 'reserved_quantity' => 0, 'damaged_quantity' => 0, 'average_cost' => '10.0000']);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $allocations->reconcile($inventory, $allocations->employeeAccount($other->employee->id), 5, $owner, 'Other holder stock');
        $before = $this->stockSnapshot();
        $context = app(PurchaseProductContextService::class)->forQuickStockPurchase($staff, $warehouse->id, [$product->id])[$product->id];
        $this->assertSame([0, 0, 0], [$context->availableQuantity, $context->reservedQuantity, $context->sellableQuantity()]);
        $this->assertSame(0, app(ProductMatchService::class)->match(new ProductMatchRequest('CX1405CT', ProductMatchContext::Receiving,
            $staff, warehouseId: $warehouse->id))->sole()->sellableQuantity);
        $this->assertSame($before, $this->stockSnapshot());
        $allocations->reconcile($inventory, $allocations->employeeAccount($staff->employee->id), 3, $owner, 'Actual own stock');
        $context = app(PurchaseProductContextService::class)->forQuickStockPurchase($staff, $warehouse->id, [$product->id])[$product->id];
        $this->assertSame(3, $context->sellableQuantity());

        $data = $this->purchaseData($warehouse, [$product]);
        $result = app(PostQuickStockPurchase::class)->handle($data, $staff);
        $this->assertSame(11, $inventory->fresh()->available_quantity);
        $this->assertSame(2, $result->purchase->items->sole()->received_quantity);
        $this->assertSame(5, InventoryAllocationBalance::query()->where('product_inventory_id', $inventory->id)
            ->whereHas('account', fn ($query) => $query->where('employee_id', $staff->employee->id))->sole()->allocated_quantity);
        $this->assertTrue(app(PostQuickStockPurchase::class)->handle($data, $staff)->replayed);
    }

    #[DataProvider('employeeRoles')]
    public function test_receiving_platform_neutrality_preserves_combined_dimensions_and_warehouse_restrictions(EmployeeRole $role): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation($role);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        $this->combinedScope($assignment, $product, $warehouse);
        $assignment->platformScope()->create(['marketplace_platform_id' => MarketplacePlatform::factory()->create()->id]);
        $this->actingAs($staff);
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $this->assertArrayHasKey($product->id, $this->productField($component)->getSearchResults($product->sku));
        foreach (['brand_id' => ProductBrand::factory()->create()->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'condition' => $product->condition === ProductCondition::New ? ProductCondition::Renewed : ProductCondition::New] as $field => $value) {
            $outside = Product::factory()->create(array_merge(['brand_id' => $product->brand_id, 'category_id' => $product->category_id,
                'condition' => $product->condition], [$field => $value]));
            $this->assertArrayNotHasKey($outside->id, $this->productField($component)->getSearchResults($outside->sku));
            try {
                app(PostQuickStockPurchase::class)->handle($this->purchaseData($warehouse, [$outside]), $staff);
                $this->fail('Receiving must preserve '.$field.' scope.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items', $exception->errors());
            }
        }
        $otherWarehouse = Warehouse::factory()->create();
        $component->set('data.warehouse_id', $otherWarehouse->id);
        $this->assertArrayNotHasKey($product->id, $this->productField($component)->getSearchResults($product->sku));
        try {
            app(PostQuickStockPurchase::class)->handle($this->purchaseData($otherWarehouse, [$product]), $staff);
            $this->fail('Explicit Warehouse restriction must remain enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertNoPosting();
    }

    #[DataProvider('employeeRoles')]
    public function test_platform_only_does_not_grant_receiving_search_preselection_context_or_posting(EmployeeRole $role): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation($role);
        $platform = MarketplacePlatform::factory()->create();
        $warehouse->update(['marketplace_platform_id' => $platform->id]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        $assignment->platformScope()->create(['marketplace_platform_id' => $platform->id]);
        $this->actingAs($staff);
        $component = Livewire::withQueryParams(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->test(QuickStockPurchase::class);
        $this->assertEmpty(collect($component->instance()->data['items'])->first()['product_id']);
        $this->assertSame([], $this->productField($component)->getSearchResults($product->sku));
        $this->assertSame([], app(PurchaseProductContextService::class)->forQuickStockPurchase($staff, $warehouse->id, [$product->id]));
        $component->mountAction(TestAction::make('bulkAddProducts')->schemaComponent(true, 'content'));
        $bulk = collect($component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_ids');
        $this->assertSame([], $bulk->getSearchResults($product->sku));
        $component->setActionData(['product_ids' => [$product->id]])->callMountedAction()->assertHasActionErrors(['product_ids.0']);
        $this->assertTrue(app(OrderResponsibilityScopeService::class)->canAccessProduct($staff, $product->id, $platform->id, $warehouse->id));
        try {
            app(PostQuickStockPurchase::class)->handle($this->purchaseData($warehouse, [$product]), $staff);
            $this->fail('Platform alone must not grant receiving eligibility.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertNoPosting();
    }

    #[DataProvider('scopeCases')]
    public function test_search_and_bulk_respect_active_scope_without_requiring_stock(string $scope): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation();
        $other = Product::factory()->create([
            'brand_id' => ProductBrand::factory()->create()->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'condition' => ProductCondition::Renewed,
        ]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        match ($scope) {
            'brand' => $assignment->brandScope()->create(['product_brand_id' => $product->brand_id]),
            'category' => $assignment->categoryScope()->create(['product_category_id' => $product->category_id]),
            'condition' => $assignment->conditionScope()->create(['product_condition' => $product->condition->value]),
            'product' => $assignment->productScope()->create(['product_id' => $product->id]),
            'warehouse' => $assignment->warehouseScope()->create(['warehouse_id' => $warehouse->id]),
            'combined' => $this->combinedScope($assignment, $product, $warehouse),
        };
        $otherWarehouse = Warehouse::factory()->create();
        $this->actingAs($staff);
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $field = $this->productField($component);
        $this->assertArrayHasKey($product->id, $field->getSearchResults($product->sku));
        if ($scope !== 'warehouse') {
            $this->assertArrayNotHasKey($other->id, $field->getSearchResults($other->sku));
        }
        $component->mountAction(TestAction::make('bulkAddProducts')->schemaComponent(true, 'content'));
        $bulk = collect($component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_ids');
        $this->assertArrayHasKey($product->id, $bulk->getSearchResults($product->sku));
        if ($scope !== 'warehouse') {
            $this->assertArrayNotHasKey($other->id, $bulk->getSearchResults($other->sku));
        }
        $component->unmountAction();
        if (in_array($scope, ['warehouse', 'combined'], true)) {
            $component->set('data.warehouse_id', $otherWarehouse->id);
            $this->assertArrayNotHasKey($product->id, $this->productField($component)->getSearchResults($product->sku));
            $component->set('data.warehouse_id', $warehouse->id);
        }
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 0]);
        $this->assertArrayHasKey($product->id, $this->productField($component)->getSearchResults($product->sku));
        $assignment->update(['status' => ResponsibilityAssignmentStatus::Inactive, 'ended_at' => now()]);
        $this->assertArrayNotHasKey($product->id, $this->productField($component)->getSearchResults($product->sku));
        $this->assertDatabaseCount('inventory_allocation_events', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_manager_search_and_crafted_bulk_ids_cannot_bypass_responsibility(): void
    {
        [$owner, $manager, $warehouse, $product] = $this->foundation(EmployeeRole::Manager);
        $this->productScope($manager, $product);
        $other = Product::factory()->create();
        $this->actingAs($manager);
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $this->assertArrayHasKey($product->id, $this->productField($component)->getSearchResults($product->sku));
        $this->assertArrayNotHasKey($other->id, $this->productField($component)->getSearchResults($other->sku));
        $before = $component->instance()->data['items'];
        $component->mountAction(TestAction::make('bulkAddProducts')->schemaComponent(true, 'content'))->setActionData(['product_ids' => [$other->id]])
            ->callMountedAction()->assertHasActionErrors(['product_ids.0']);
        $this->assertSame($before, $component->instance()->data['items']);
        $component->setActionData(['product_ids' => [$product->id]])->callMountedAction()->assertHasNoActionErrors();
        $this->assertContains($product->id, array_map('intval', array_column($component->instance()->data['items'], 'product_id')));
    }

    public function test_receiving_ignores_platform_metadata_without_changing_order_platform_scope(): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation();
        $platform = MarketplacePlatform::factory()->create();
        $assignment = $this->productScope($staff, $product);
        $assignment->platformScope()->create(['marketplace_platform_id' => $platform->id]);
        $matcher = app(ProductMatchService::class);
        $this->assertSame($product->id, $matcher->match(new ProductMatchRequest($product->sku, ProductMatchContext::Receiving, $staff,
            warehouseId: $warehouse->id, platformId: MarketplacePlatform::factory()->create()->id))->sole()->productId);
        $scope = app(OrderResponsibilityScopeService::class);
        $this->assertFalse($scope->canAccessProduct($staff, $product->id, null, $warehouse->id));
        $this->assertFalse($scope->canAccessProduct($staff, $product->id, MarketplacePlatform::factory()->create()->id, $warehouse->id));
        $this->assertTrue($scope->canAccessProduct($staff, $product->id, $platform->id, $warehouse->id));
        $warehouse->update(['marketplace_platform_id' => $platform->id]);
        $this->assertSame($product->id, $matcher->match(new ProductMatchRequest($product->sku, ProductMatchContext::Receiving, $staff,
            warehouseId: $warehouse->id))->sole()->productId);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
    }

    #[DataProvider('employeeRoles')]
    public function test_stock_context_and_matcher_never_expose_other_or_system_stock_and_are_read_only(EmployeeRole $role): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation($role);
        $this->productScope($staff, $product);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 12, 'reserved_quantity' => 0, 'damaged_quantity' => 3]);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $allocations->reconcile($inventory, $allocations->employeeAccount($other->employee->id), 5, $owner, 'Other employee stock');
        $context = app(PurchaseProductContextService::class);
        $before = $this->stockSnapshot();
        $mine = $context->forQuickStockPurchase($staff, $warehouse->id, [$product->id])[$product->id];
        $this->assertSame([0, 0, 0, 0], [$mine->availableQuantity, $mine->reservedQuantity, $mine->sellableQuantity(), $mine->damagedQuantity]);
        $this->assertSame(0, app(ProductMatchService::class)->match(new ProductMatchRequest($product->sku, ProductMatchContext::Receiving,
            $staff, warehouseId: $warehouse->id))->sole()->sellableQuantity);
        $this->assertSame($before, $this->stockSnapshot());
        $account = $allocations->employeeAccount($staff->employee->id);
        $allocations->reconcile($inventory, $account, 4, $owner, 'Own stock');
        // Model an existing reservation; the lookup itself must not mutate it.
        DB::table('inventory_allocation_balances')->where('account_id', $account->id)->update(['reserved_quantity' => 1]);
        DB::table('product_inventories')->where('id', $inventory->id)->update(['reserved_quantity' => 1]);
        $before = $this->stockSnapshot();
        $mine = $context->forQuickStockPurchase($staff, $warehouse->id, [$product->id])[$product->id];
        $this->assertSame([4, 1, 3, 0], [$mine->availableQuantity, $mine->reservedQuantity, $mine->sellableQuantity(), $mine->damagedQuantity]);
        $this->actingAs($staff);
        $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $lineKey = array_key_first($component->instance()->data['items']);
        $component->set("data.items.{$lineKey}.product_id", (string) $product->id)
            ->assertSee('My available 4; My reserved 1; My sellable 3')->assertDontSee('Avail 12');
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertNull($mine->inventoryAverageCost);
        $this->assertNull($mine->productCostPrice);
    }

    public function test_owner_and_admin_keep_broad_search_and_company_stock(): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation();
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 12, 'reserved_quantity' => 2, 'damaged_quantity' => 3]);
        foreach ([$owner, $this->responsibilityUser(EmployeeRole::Admin)] as $actor) {
            $this->actingAs($actor);
            $component = Livewire::test(QuickStockPurchase::class)->fillForm(['warehouse_id' => $warehouse->id]);
            $this->assertArrayHasKey($product->id, $this->productField($component)->getSearchResults($product->sku));
            $context = app(PurchaseProductContextService::class)->forQuickStockPurchase($actor, $warehouse->id, [$product->id])[$product->id];
            $this->assertSame([12, 2, 10, 3], [$context->availableQuantity, $context->reservedQuantity, $context->sellableQuantity(), $context->damagedQuantity]);
        }
    }

    public function test_direct_url_and_context_lookup_reject_unauthorized_product(): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation();
        $this->actingAs($staff);
        $component = Livewire::withQueryParams(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->test(QuickStockPurchase::class);
        $this->assertEmpty(collect($component->instance()->data['items'])->first()['product_id']);
        $this->assertSame([], app(PurchaseProductContextService::class)->forQuickStockPurchase($staff, $warehouse->id, [$product->id]));
        $this->productScope($staff, $product);
        $component = Livewire::withQueryParams(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->test(QuickStockPurchase::class);
        $this->assertSame($product->id, (int) collect($component->instance()->data['items'])->first()['product_id']);
    }

    #[DataProvider('employeeRoles')]
    public function test_crafted_mixed_payload_is_rejected_before_any_posting(EmployeeRole $role): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation($role);
        $this->productScope($staff, $product);
        $other = Product::factory()->create();
        $data = $this->purchaseData($warehouse, [$product, $other]);
        $references = DB::table('reference_sequences')->get()->toJson();
        try {
            app(PostQuickStockPurchase::class)->handle($data, $staff);
            $this->fail('Crafted product must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('active Responsibility scope', $exception->errors()['items'][0]);
        }
        $this->assertNoPosting();
        $this->assertSame($references, DB::table('reference_sequences')->get()->toJson());
    }

    public function test_crafted_receipt_cannot_bypass_the_selected_warehouse_scope(): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation();
        $assignment = $this->productScope($staff, $product);
        $assignment->warehouseScope()->create(['warehouse_id' => $warehouse->id]);
        $otherWarehouse = Warehouse::factory()->create();
        try {
            app(PostQuickStockPurchase::class)->handle($this->purchaseData($otherWarehouse, [$product]), $staff);
            $this->fail('An out-of-scope Warehouse must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('active Responsibility scope', $exception->errors()['items'][0]);
        }
        $this->assertNoPosting();
    }

    public function test_authorized_zero_stock_product_posts_and_retry_does_not_duplicate(): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation();
        $this->productScope($staff, $product)->update(['assign_stock_by_default' => true]);
        $data = $this->purchaseData($warehouse, [$product]);
        $result = app(PostQuickStockPurchase::class)->handle($data, $staff);
        $this->assertSame(2, ProductInventory::query()->sole()->available_quantity);
        $this->assertSame(2, InventoryAllocationBalance::query()->whereHas('account', fn ($q) => $q->where('employee_id', $staff->employee->id))->sole()->allocated_quantity);
        $replay = app(PostQuickStockPurchase::class)->handle($data, $staff);
        $this->assertTrue($replay->replayed);
        $this->assertSame($result->purchase->id, $replay->purchase->id);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_responsibility_is_revalidated_inside_transaction_after_initial_validation(): void
    {
        [$owner, $staff, $warehouse, $product] = $this->foundation();
        $assignment = $this->productScope($staff, $product);
        $documents = app(PurchaseDocumentService::class);
        $calls = 0;
        $transactionLevel = DB::transactionLevel();
        $this->mock(PurchaseDocumentService::class)->shouldReceive('prepare')->twice()
            ->andReturnUsing(function ($data, $purchase = null, $actor = null, $receivingScope = false) use ($documents, $staff, $assignment, $transactionLevel, &$calls) {
                $this->assertSame($staff->id, $actor?->id);
                $this->assertTrue($receivingScope);
                $this->assertSame($transactionLevel + ($calls === 0 ? 0 : 1), DB::transactionLevel());
                $calls++;
                $prepared = $documents->prepare($data, $purchase, $actor, $receivingScope);
                if ($calls === 1) {
                    $assignment->update(['status' => ResponsibilityAssignmentStatus::Inactive, 'ended_at' => now()]);
                }

                return $prepared;
            });
        try {
            app(PostQuickStockPurchase::class)->handle($this->purchaseData($warehouse, [$product]), $staff);
            $this->fail('Ended responsibility must fail locked revalidation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertSame(2, $calls);
        $this->assertNoPosting();
    }

    private function foundation(EmployeeRole $role = EmployeeRole::Staff): array
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser($role);
        app(EmployeePermissionOverrideService::class)->change($staff->employee, PurchasePermission::QuickReceive->value,
            EmployeePermissionEffect::Allow, 'Receive responsible stock', $owner);

        return [$owner, $staff, Warehouse::factory()->create(), Product::factory()->create()];
    }

    private function productScope(User $user, Product $product): ResponsibilityAssignment
    {
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $user->employee->id]);
        $assignment->productScope()->create(['product_id' => $product->id]);

        return $assignment;
    }

    private function combinedScope(ResponsibilityAssignment $assignment, Product $product, Warehouse $warehouse): void
    {
        $assignment->brandScope()->create(['product_brand_id' => $product->brand_id]);
        $assignment->categoryScope()->create(['product_category_id' => $product->category_id]);
        $assignment->conditionScope()->create(['product_condition' => $product->condition->value]);
        $assignment->warehouseScope()->create(['warehouse_id' => $warehouse->id]);
    }

    private function productField($component): Select
    {
        return collect($component->instance()->getSchema('content')->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');
    }

    private function purchaseData(Warehouse $warehouse, array $products): QuickStockPurchaseData
    {
        return new QuickStockPurchaseData(warehouseId: $warehouse->id, purchaseDate: now()->toDateString(),
            items: array_map(fn (Product $product) => new PurchaseItemData($product->id, 2, '10.0000'), $products),
            idempotencyKey: (string) Str::uuid());
    }

    private function stockSnapshot(): array
    {
        return [DB::table('product_inventories')->get()->toJson(), InventoryAllocationBalance::all()->toJson(), InventoryAllocationEvent::all()->toJson()];
    }

    private function assertNoPosting(): void
    {
        foreach (['purchases', 'purchase_receipts', 'product_inventories', 'stock_movements', 'inventory_allocation_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
