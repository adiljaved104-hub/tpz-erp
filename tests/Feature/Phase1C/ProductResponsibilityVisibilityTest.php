<?php

namespace Tests\Feature\Phase1C;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\ExportProducts;
use App\Actions\Products\SetProductStatus;
use App\Actions\Products\UpdateProduct;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\DTOs\Products\ChangeProductStatusData;
use App\DTOs\Products\CreateProductData;
use App\DTOs\Products\ProductExportData;
use App\DTOs\Products\UpdateProductData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ProductMatchContext;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Enums\PurchasePermission;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Resources\Products\Pages\CreateProduct as CreateProductPage;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\ProductAuthorization;
use App\Services\ProductIntelligence\ProductMatchService;
use App\Services\Products\ProductExportService;
use App\Services\Search\CatalogPeopleSearchProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ProductResponsibilityVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_staff_list_and_search_show_matching_products_regardless_of_creator(): void
    {
        [$owner, $staff, $other] = $this->users();
        $brand = ProductBrand::factory()->create(['name' => 'HP scope', 'normalized_name' => 'hp-scope']);
        $outsideBrand = ProductBrand::factory()->create(['name' => 'Dell outside', 'normalized_name' => 'dell-outside']);
        $ownerProduct = $this->product($brand, ['name' => 'Legacy Search Owner Product', 'created_by_user_id' => $owner->id]);
        $otherProduct = $this->product($brand, ['name' => 'Legacy Search Employee Product', 'created_by_user_id' => $other->id]);
        $staffProduct = $this->product($brand, ['name' => 'Legacy Search Staff Product', 'created_by_user_id' => $staff->id]);
        $outside = $this->product($outsideBrand, ['name' => 'Legacy Search Outside Product', 'created_by_user_id' => $staff->id]);
        $this->assign($owner, $staff, ['brand_id' => $brand->id]);

        $this->actingAs($staff);
        $this->assertEqualsCanonicalizing([$ownerProduct->id, $otherProduct->id, $staffProduct->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$ownerProduct, $otherProduct, $staffProduct])
            ->assertCanNotSeeTableRecords([$outside])->searchTable('Legacy Search')->assertCountTableRecords(3);
        $results = app(CatalogPeopleSearchProvider::class)->search($staff, 'Legacy Search', 20)->where('group', 'Products');
        $this->assertEqualsCanonicalizing([$ownerProduct->id, $otherProduct->id, $staffProduct->id], $results->pluck('mobileTarget.id')->all());
        $this->assertDatabaseCount('responsibility_assignments', 1);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
    }

    public function test_no_matching_responsibility_means_no_product_visibility_regardless_of_creator(): void
    {
        [, $staff] = $this->users();
        $ownerProduct = Product::factory()->create(['name' => 'Owner product outside scope']);
        $staffProduct = Product::factory()->create(['name' => 'Staff product outside scope', 'created_by_user_id' => $staff->id]);
        $this->actingAs($staff);
        $this->assertSame([], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse($staff->can('view', $ownerProduct));
        $this->assertFalse($staff->can('view', $staffProduct));
        $this->assertFalse($staff->can('update', $staffProduct));
        $this->get(ProductResource::getUrl('view', ['record' => $staffProduct]))->assertNotFound();
    }

    public function test_direct_view_and_update_use_permission_and_responsibility_independently_of_creator(): void
    {
        [$owner, $staff] = $this->users();
        $brand = ProductBrand::factory()->create(['name' => 'Direct scope', 'normalized_name' => 'direct-scope']);
        $outsideBrand = ProductBrand::factory()->create(['name' => 'Direct outside', 'normalized_name' => 'direct-outside']);
        $inScope = $this->product($brand, ['name' => 'Owner created in scope', 'created_by_user_id' => $owner->id]);
        $outOfScope = $this->product($outsideBrand, ['name' => 'Staff created outside scope', 'created_by_user_id' => $staff->id]);
        $this->assign($owner, $staff, ['brand_id' => $brand->id]);
        $this->actingAs($staff);
        $this->assertTrue($staff->can('view', $inScope));
        $this->assertTrue($staff->can('update', $inScope));
        $this->assertFalse($staff->can('view', $outOfScope));
        $this->assertFalse($staff->can('update', $outOfScope));
        $this->get(ProductResource::getUrl('view', ['record' => $inScope]))->assertOk();
        $this->get(ProductResource::getUrl('edit', ['record' => $inScope]))->assertOk();
        $this->get(ProductResource::getUrl('view', ['record' => $outOfScope]))->assertNotFound();
        $updated = app(UpdateProduct::class)->handle($inScope, $this->updateData($inScope), $staff);
        $this->assertSame('Updated in-scope Product', $updated->name);

        try {
            app(UpdateProduct::class)->handle($outOfScope, $this->updateData($outOfScope), $staff);
            $this->fail('Product update outside Responsibility must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('Staff created outside scope', $outOfScope->refresh()->name);
        }
    }

    public function test_access_control_deny_and_inactive_employee_still_block_in_scope_record(): void
    {
        [$owner, $staff] = $this->users();
        $brand = ProductBrand::factory()->create(['name' => 'Denied scope', 'normalized_name' => 'denied-scope']);
        $product = $this->product($brand, ['created_by_user_id' => $staff->id]);
        $this->assign($owner, $staff, ['brand_id' => $brand->id]);
        $service = app(EmployeePermissionOverrideService::class);
        $service->change($staff->employee, ProductPermission::View->value, EmployeePermissionEffect::Deny, 'Denied Product module access', $owner);
        $this->assertFalse($staff->can('view', $product));
        $this->actingAs($staff)->get(ProductResource::getUrl('view', ['record' => $product]))->assertForbidden();
        $service->change($staff->employee, ProductPermission::Update->value, EmployeePermissionEffect::Deny, 'Denied update', $owner);
        try {
            app(UpdateProduct::class)->handle($product, $this->updateData($product), $staff);
            $this->fail('Responsibility never overrides a permission denial.');
        } catch (AuthorizationException) {
            $this->assertSame($product->name, $product->refresh()->name);
        }
        $staff->employee->update(['status' => false]);
        $this->assertFalse(app(ProductAuthorization::class)->allows($staff->fresh(), ProductPermission::Create));
    }

    public static function transitions(): array
    {
        return [
            ['active', 'inactive'], ['active', 'discontinued'], ['inactive', 'active'],
            ['inactive', 'discontinued'], ['discontinued', 'active'], ['discontinued', 'inactive'],
        ];
    }

    #[DataProvider('transitions')]
    public function test_status_action_requires_responsibility_even_when_permissions_are_granted(string $from, string $to): void
    {
        [$owner, $staff, $other] = $this->users();
        $brand = ProductBrand::factory()->create(['name' => 'Status scope', 'normalized_name' => 'status-scope']);
        $outsideBrand = ProductBrand::factory()->create(['name' => 'Status outside', 'normalized_name' => 'status-outside']);
        $inScope = $this->product($brand, ['created_by_user_id' => $other->id, 'status' => $from]);
        $outside = $this->product($outsideBrand, ['created_by_user_id' => $staff->id, 'status' => $from]);
        $this->assign($owner, $staff, ['brand_id' => $brand->id]);
        foreach (ProductPermission::cases() as $permission) {
            $this->assertFalse(app(ProductAuthorization::class)->allows($staff, $permission, $outside));
        }
        try {
            app(SetProductStatus::class)->handle($outside, new ChangeProductStatusData($to, 'Tampered record target'), $staff);
            $this->fail('An out-of-scope Product cannot bypass row authorization.');
        } catch (AuthorizationException) {
            $this->assertSame(ProductStatus::from($from), $outside->refresh()->status);
        }
        $result = app(SetProductStatus::class)->handle($inScope, new ChangeProductStatusData($to, 'In-scope Product status'), $staff);
        $this->assertSame(ProductStatus::from($to), $result->status);
        $this->assertSame($other->id, $result->created_by_user_id);
    }

    public function test_server_creation_attributes_actor_and_ignores_spoofed_creator_form_data(): void
    {
        [$owner, $staff, $other] = $this->users();
        $brand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();
        Livewire::actingAs($staff)->test(CreateProductPage::class)
            ->assertFormFieldDoesNotExist('created_by_user_id')
            ->fillForm(['name' => 'Staff original catalog Product', 'brand_id' => $brand->id, 'category_id' => $category->id, 'condition' => 'new', 'warranty' => 12])
            ->set('data.created_by_user_id', $other->id)
            ->call('create')->assertHasNoFormErrors();
        $product = Product::query()->sole();
        $this->assertSame($staff->id, $product->created_by_user_id);
        $this->assertSame($staff->id, ActivityLog::query()->where('event', 'product.created')->sole()->actor_user_id);
        $this->assertSame([], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse($staff->can('view', $product));
        $this->assign($owner, $staff, ['brand_id' => $brand->id]);
        $this->assertSame([$product->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->actingAs($other);
        $this->assertSame([], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->assertDatabaseCount('product_inventories', 0);
        $this->assertDatabaseCount('inventory_allocation_events', 0);
    }

    public function test_creator_cannot_be_mass_assigned_or_changed_by_an_editor(): void
    {
        [$owner, $staff, $other] = $this->users();
        $product = app(CreateProduct::class)->handle(new CreateProductData(
            name: 'Original actor', brandId: ProductBrand::factory()->create()->id,
            categoryId: ProductCategory::factory()->create()->id,
        ), $staff);
        $this->assertFalse($product->isFillable('created_by_user_id'));
        app(UpdateProduct::class)->handle($product, $this->updateData($product), $owner);
        $this->assertSame($staff->id, $product->refresh()->created_by_user_id);
        try {
            $product->forceFill(['created_by_user_id' => $other->id])->save();
            $this->fail('An accidental forceFill must not replace original creator history.');
        } catch (RuntimeException $e) {
            $this->assertSame('The original Product creator is immutable.', $e->getMessage());
        }
        $this->assertSame($staff->id, $product->refresh()->created_by_user_id);
    }

    public function test_staff_export_is_responsibility_scoped_even_with_search_and_direct_service_calls(): void
    {
        [$owner, $staff, $other] = $this->users();
        $brand = ProductBrand::factory()->create(['name' => 'Export scope', 'normalized_name' => 'export-scope']);
        $outsideBrand = ProductBrand::factory()->create(['name' => 'Export outside', 'normalized_name' => 'export-outside']);
        $ownerCreated = $this->product($brand, ['name' => 'Export Responsibility Owner', 'created_by_user_id' => $staff->id]);
        $legacy = $this->product($brand, ['name' => 'Export Responsibility Legacy']);
        $outside = $this->product($outsideBrand, ['name' => 'Export Responsibility Outside', 'created_by_user_id' => $other->id]);
        $this->assign($owner, $staff, ['brand_id' => $brand->id]);
        $csv = $this->csv(app(ExportProducts::class)->handle(new ProductExportData(search: 'Export Responsibility'), $staff));
        $this->assertStringContainsString($ownerCreated->sku, $csv);
        $this->assertStringContainsString($legacy->sku, $csv);
        $this->assertStringNotContainsString($outside->sku, $csv);
        $this->assertNotContains('cost_price', str_getcsv(strtok($csv, "\n")));
        $this->assertSame(2, ActivityLog::query()->where('event', 'product.exported')->sole()->properties['exported_row_count']);
        $csv = $this->csv(app(ProductExportService::class)->stream(new ProductExportData, $staff));
        $this->assertStringContainsString($legacy->sku, $csv);
        $this->assertStringNotContainsString($outside->sku, $csv);
    }

    public static function unrestrictedRoles(): array
    {
        return [[EmployeeRole::Owner], [EmployeeRole::Admin]];
    }

    #[DataProvider('unrestrictedRoles')]
    public function test_owner_and_admin_product_catalog_behavior_remains_broad(EmployeeRole $role): void
    {
        [$owner, $staff] = $this->users();
        $actor = $role === EmployeeRole::Owner ? $owner : $this->responsibilityUser($role);
        $known = Product::factory()->create(['created_by_user_id' => $staff->id]);
        $legacy = Product::factory()->create();
        $this->actingAs($actor);
        $this->assertEqualsCanonicalizing([$known->id, $legacy->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue($actor->can('view', $known));
        $csv = $this->csv(app(ExportProducts::class)->handle(new ProductExportData, $actor));
        $this->assertStringContainsString($known->sku, $csv);
        $this->assertStringContainsString($legacy->sku, $csv);
        $this->assertSame($role === EmployeeRole::Owner, in_array('cost_price', str_getcsv(strtok($csv, "\n")), true));
    }

    public function test_manager_uses_responsibility_scope_without_changing_operational_product_search(): void
    {
        [$owner] = $this->users();
        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        $brand = ProductBrand::factory()->create(['name' => 'Manager scope', 'normalized_name' => 'manager-scope']);
        $outsideBrand = ProductBrand::factory()->create(['name' => 'Manager outside', 'normalized_name' => 'manager-outside']);
        $product = $this->product($brand, ['created_by_user_id' => $owner->id, 'name' => 'Matching manager master Product']);
        $outside = $this->product($outsideBrand, ['name' => 'Out of scope Product']);
        app(EmployeePermissionOverrideService::class)->change($manager->employee, PurchasePermission::Create->value,
            EmployeePermissionEffect::Allow, 'Operational purchase selection', $owner);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData([
            'employee' => $manager->employee, 'brand' => $brand,
        ]), $owner);
        $this->actingAs($manager);
        $this->assertTrue(ProductResource::getEloquentQuery()->whereKey($product)->exists());
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($outside)->exists());
        $this->assertTrue(Product::query()->whereKey($outside)->exists());
        $results = app(ProductMatchService::class)->match(new ProductMatchRequest($product->sku, ProductMatchContext::Purchase, $owner));
        $this->assertTrue($results->pluck('productId')->contains($product->id));
        $managerResults = app(ProductMatchService::class)->match(new ProductMatchRequest($product->sku, ProductMatchContext::Purchase, $manager,
            warehouseId: Warehouse::factory()->create()->id));
        $this->assertTrue($managerResults->pluck('productId')->contains($product->id));
        $this->assertTrue(ProductResource::getEloquentQuery()->whereKey($product)->exists());
        $this->assertDatabaseCount('responsibility_assignments', 1);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
    }

    public function test_category_responsibility_exposes_matching_category_products_only(): void
    {
        [$owner, $staff] = $this->users();
        $brand = ProductBrand::factory()->create();
        $categoryA = ProductCategory::factory()->create(['name' => 'Scope Category A', 'normalized_name' => 'scope-category-a']);
        $categoryB = ProductCategory::factory()->create(['name' => 'Scope Category B', 'normalized_name' => 'scope-category-b']);
        $categoryMatch = $this->product($brand, ['category_id' => $categoryA->id, 'category' => $categoryA->name]);
        $categoryMatchOtherBrand = $this->product(ProductBrand::factory()->create(), ['category_id' => $categoryA->id, 'category' => $categoryA->name]);
        $categoryMiss = $this->product($brand, ['category_id' => $categoryB->id, 'category' => $categoryB->name]);
        $this->assign($owner, $staff, ['category_id' => $categoryA->id]);

        $this->actingAs($staff);
        $this->assertEqualsCanonicalizing([$categoryMatch->id, $categoryMatchOtherBrand->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($categoryMiss)->exists());
    }

    public function test_condition_responsibility_exposes_matching_condition_products_only(): void
    {
        [$owner] = $this->users();
        $conditionManager = $this->responsibilityUser(EmployeeRole::Manager);
        $renewed = Product::factory()->create(['condition' => ProductCondition::Renewed]);
        $new = Product::factory()->create(['condition' => ProductCondition::New]);
        $this->assign($owner, $conditionManager, ['condition' => ProductCondition::Renewed->value]);
        $this->actingAs($conditionManager);
        $this->assertEqualsCanonicalizing([$renewed->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($new)->exists());
    }

    public function test_product_responsibility_exposes_only_the_explicitly_assigned_product(): void
    {
        [$owner, , $other] = $this->users();
        $exactlyAssigned = Product::factory()->create();
        $unassigned = Product::factory()->create();
        $this->assign($owner, $other, ['product_id' => $exactlyAssigned->id]);
        $this->actingAs($other);
        $this->assertEqualsCanonicalizing([$exactlyAssigned->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($unassigned)->exists());
    }

    public function test_warehouse_and_quantity_responsibilities_follow_existing_scope_behavior(): void
    {
        [$owner, $staff, $other] = $this->users();
        $warehouse = Warehouse::factory()->create(['name' => 'Scoped Product Warehouse']);
        $otherWarehouse = Warehouse::factory()->create(['name' => 'Other Product Warehouse']);
        $inWarehouse = Product::factory()->create();
        $outsideWarehouse = Product::factory()->create();
        ProductInventory::factory()->create(['product_id' => $inWarehouse->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4]);
        ProductInventory::factory()->create(['product_id' => $outsideWarehouse->id, 'warehouse_id' => $otherWarehouse->id, 'available_quantity' => 4]);
        $this->assign($owner, $staff, ['warehouse_id' => $warehouse->id]);
        $this->actingAs($staff);
        $this->assertEqualsCanonicalizing([$inWarehouse->id], ProductResource::getEloquentQuery()->pluck('id')->all());

        $quantityProduct = Product::factory()->create();
        $quantityInventory = ProductInventory::factory()->create(['product_id' => $quantityProduct->id, 'available_quantity' => 5]);
        $otherProduct = Product::factory()->create();
        ProductInventory::factory()->create(['product_id' => $otherProduct->id, 'available_quantity' => 5]);
        $this->assign($owner, $other, [
            'product_inventory_id' => $quantityInventory->id,
            'assigned_quantity' => 1,
        ], ResponsibilityAssignmentMode::Quantity);
        $this->actingAs($other);
        $this->assertEqualsCanonicalizing([$quantityProduct->id], ProductResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_sensitive_fields_and_export_still_require_their_explicit_permissions(): void
    {
        [$owner] = $this->users();
        $viewer = $this->responsibilityUser(EmployeeRole::Staff);
        $email = 'product-viewer-'.$viewer->id.'@techpointzone.com';
        $viewer->update(['email' => $email]);
        $viewer->employee->update(['email' => $email]);
        app(EmployeePermissionOverrideService::class)->change($viewer->employee, ProductPermission::View->value,
            EmployeePermissionEffect::Allow, 'Product catalog access', $owner);
        $brand = ProductBrand::factory()->create(['name' => 'Private fields scope', 'normalized_name' => 'private-fields-scope']);
        $product = $this->product($brand, ['selling_price' => '777.00', 'cost_price' => '999.1234']);
        $this->assign($owner, $viewer, ['brand_id' => $brand->id]);

        $this->actingAs($viewer);
        $this->assertTrue($viewer->can('view', $product));
        $this->assertFalse(app(ProductAuthorization::class)->allows($viewer, ProductPermission::ViewSellingPrice, $product));
        $this->assertFalse(app(ProductAuthorization::class)->allows($viewer, ProductPermission::ViewCostPrice, $product));
        $this->assertFalse(app(ProductAuthorization::class)->allows($viewer, ProductPermission::Update, $product));
        $this->assertFalse(app(ProductAuthorization::class)->allows($viewer, ProductPermission::Export));
        $record = ProductResource::getEloquentQuery()->sole();
        $this->assertArrayNotHasKey('selling_price', $record->getAttributes());
        $this->assertArrayNotHasKey('cost_price', $record->getAttributes());
        Livewire::actingAs($viewer)->test(ListProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->assertDontSee('777.00')
            ->assertDontSee('999.1234');

        try {
            app(ExportProducts::class)->handle(new ProductExportData, $viewer);
            $this->fail('Product export still requires its own permission.');
        } catch (AuthorizationException) {
            $this->assertTrue($viewer->can('view', $product));
        }
    }

    public function test_staff_without_product_view_cannot_open_the_product_module(): void
    {
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $product = Product::factory()->create(['created_by_user_id' => $staff->id]);
        $this->actingAs($staff)->get('/admin/products')->assertForbidden();
        $this->assertFalse($staff->can('view', $product));
    }

    private function users(): array
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        foreach ([$owner, $staff, $other] as $user) {
            $email = 'product-scope-test-'.$user->id.'@techpointzone.com';
            $user->update(['email' => $email]);
            $user->employee->update(['email' => $email]);
        }
        foreach ([$staff, $other] as $user) {
            foreach ([ProductPermission::View, ProductPermission::Create, ProductPermission::Update, ProductPermission::Export,
                ProductPermission::ViewSellingPrice, ProductPermission::EditSellingPrice, ProductPermission::Activate,
                ProductPermission::Deactivate, ProductPermission::Discontinue, ProductPermission::Reactivate] as $permission) {
                app(EmployeePermissionOverrideService::class)->change($user->employee, $permission->value,
                    EmployeePermissionEffect::Allow, 'Approved Product module regression access', $owner);
            }
        }

        return [$owner, $staff, $other];
    }

    private function updateData(Product $product): UpdateProductData
    {
        return new UpdateProductData(name: 'Updated in-scope Product', brandId: $product->brand_id, categoryId: $product->category_id, condition: $product->condition);
    }

    private function product(ProductBrand $brand, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'brand_id' => $brand->id,
            'brand' => $brand->name,
        ], $attributes));
    }

    private function assign(User $owner, User $employee, array $scope, ResponsibilityAssignmentMode $mode = ResponsibilityAssignmentMode::Scope): void
    {
        app(CreateResponsibilityAssignment::class)->handle(new CreateResponsibilityAssignmentData(
            employeeId: $employee->employee->id,
            mode: $mode,
            brandId: $scope['brand_id'] ?? null,
            platformId: null,
            productId: $scope['product_id'] ?? null,
            productInventoryId: $scope['product_inventory_id'] ?? null,
            assignedQuantity: $mode === ResponsibilityAssignmentMode::Quantity ? ($scope['assigned_quantity'] ?? 1) : null,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Product module visibility test',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
            categoryId: $scope['category_id'] ?? null,
            warehouseId: $scope['warehouse_id'] ?? null,
            condition: isset($scope['condition']) ? ProductCondition::from($scope['condition']) : null,
        ), $owner);
    }

    private function csv(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
