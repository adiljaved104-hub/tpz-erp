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
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductMatchContext;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Enums\PurchasePermission;
use App\Filament\Resources\Products\Pages\CreateProduct as CreateProductPage;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\ProductAuthorization;
use App\Services\ProductIntelligence\ProductMatchService;
use App\Services\Products\ProductExportService;
use App\Services\Search\CatalogPeopleSearchProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ProductCreatorVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_staff_list_and_search_show_only_originally_created_products_without_responsibility(): void
    {
        [$owner, $staff, $other] = $this->users();
        $own = Product::factory()->create(['name' => 'Creator Search Own', 'created_by_user_id' => $staff->id]);
        $foreign = Product::factory()->create(['name' => 'Creator Search Foreign', 'created_by_user_id' => $other->id]);
        $unknown = Product::factory()->create(['name' => 'Creator Search Legacy']);
        $this->actingAs($staff);
        $this->assertSame([$own->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign, $unknown])
            ->searchTable('Creator Search')->assertCountTableRecords(1);
        $this->assertSame($staff->id, ProductResource::getEloquentQuery()->sole()->created_by_user_id);
        $results = app(CatalogPeopleSearchProvider::class)->search($staff, 'Creator Search', 20)->where('group', 'Products');
        $this->assertSame([$own->id], $results->pluck('mobileTarget.id')->all());
        $this->assertDatabaseCount('responsibility_assignments', 0);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
    }

    public function test_direct_view_and_edit_urls_and_policies_block_foreign_and_unattributed_products(): void
    {
        [, $staff, $other] = $this->users();
        $this->actingAs($staff);
        foreach ([$other->id, null] as $creatorId) {
            $product = Product::factory()->create(['created_by_user_id' => $creatorId]);
            $this->assertFalse($staff->can('view', $product));
            $this->assertFalse($staff->can('update', $product));
            $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertNotFound();
            $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertNotFound();
            $before = $product->name;
            try {
                app(UpdateProduct::class)->handle($product, $this->updateData($product), $staff);
                $this->fail('Direct update of another creator\'s Product must fail.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $product->refresh()->name);
            }
        }
    }

    public function test_own_view_update_and_edit_keep_creator_and_financial_restrictions(): void
    {
        [, $staff] = $this->users();
        $product = Product::factory()->create(['created_by_user_id' => $staff->id, 'cost_price' => '9876.5432']);
        $this->actingAs($staff);
        $this->assertTrue($staff->can('view', $product));
        $this->assertTrue($staff->can('update', $product));
        $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk()->assertDontSee('9876.5432');
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk()->assertDontSee('Cost Price');
        $this->assertArrayNotHasKey('cost_price', ProductResource::getEloquentQuery()->sole()->getAttributes());
        $updated = app(UpdateProduct::class)->handle($product, $this->updateData($product), $staff);
        $this->assertSame($staff->id, $updated->created_by_user_id);
        $this->assertSame('Updated own Product', $updated->name);
        $this->assertSame($staff->id, $updated->createdBy->id);
        $this->assertFalse(app(ProductAuthorization::class)->allows($staff, ProductPermission::EditCostPrice, $updated));
    }

    public function test_access_control_deny_and_inactive_employee_still_block_owned_record(): void
    {
        [$owner, $staff] = $this->users();
        $product = Product::factory()->create(['created_by_user_id' => $staff->id]);
        $service = app(EmployeePermissionOverrideService::class);
        $service->change($staff->employee, ProductPermission::View->value, EmployeePermissionEffect::Deny, 'Denied even for own Products', $owner);
        $this->assertFalse($staff->can('view', $product));
        $this->actingAs($staff)->get(ProductResource::getUrl('view', ['record' => $product]))->assertForbidden();
        $service->change($staff->employee, ProductPermission::Update->value, EmployeePermissionEffect::Deny, 'Denied update', $owner);
        try {
            app(UpdateProduct::class)->handle($product, $this->updateData($product), $staff);
            $this->fail('Creator attribution never grants permission.');
        } catch (AuthorizationException) {
            $this->assertNotSame('Updated own Product', $product->refresh()->name);
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
    public function test_status_action_requires_creator_even_when_all_action_permissions_are_granted(string $from, string $to): void
    {
        [, $staff, $other] = $this->users();
        $own = Product::factory()->create(['created_by_user_id' => $staff->id, 'status' => $from]);
        $foreign = Product::factory()->create(['created_by_user_id' => $other->id, 'status' => $from]);
        foreach (ProductPermission::cases() as $permission) {
            $this->assertFalse(app(ProductAuthorization::class)->allows($staff, $permission, $foreign));
        }
        try {
            app(SetProductStatus::class)->handle($foreign, new ChangeProductStatusData($to, 'Tampered record target'), $staff);
            $this->fail('A supplied foreign record cannot bypass row authorization.');
        } catch (AuthorizationException) {
            $this->assertSame(ProductStatus::from($from), $foreign->refresh()->status);
        }
        $result = app(SetProductStatus::class)->handle($own, new ChangeProductStatusData($to, 'Own Product status'), $staff);
        $this->assertSame(ProductStatus::from($to), $result->status);
        $this->assertSame($staff->id, $result->created_by_user_id);
    }

    public function test_server_creation_attributes_actor_and_ignores_spoofed_creator_form_data(): void
    {
        [, $staff, $other] = $this->users();
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
        $this->assertSame([$product->id], ProductResource::getEloquentQuery()->pluck('id')->all());
        $this->actingAs($other);
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($product)->exists());
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

    public function test_staff_export_is_creator_scoped_even_with_search_and_direct_service_calls(): void
    {
        [, $staff, $other] = $this->users();
        $own = Product::factory()->create(['name' => 'Export Creator Own', 'created_by_user_id' => $staff->id]);
        $foreign = Product::factory()->create(['name' => 'Export Creator Foreign', 'created_by_user_id' => $other->id]);
        $legacy = Product::factory()->create(['name' => 'Export Creator Legacy']);
        $csv = $this->csv(app(ExportProducts::class)->handle(new ProductExportData(search: 'Export Creator'), $staff));
        $this->assertStringContainsString($own->sku, $csv);
        $this->assertStringNotContainsString($foreign->sku, $csv);
        $this->assertStringNotContainsString($legacy->sku, $csv);
        $this->assertNotContains('cost_price', str_getcsv(strtok($csv, "\n")));
        $this->assertSame(1, ActivityLog::query()->where('event', 'product.exported')->sole()->properties['exported_row_count']);
        $csv = $this->csv(app(ProductExportService::class)->stream(new ProductExportData, $staff));
        $this->assertStringNotContainsString($foreign->sku, $csv);
        $this->assertStringNotContainsString($legacy->sku, $csv);
    }

    public static function unscopedRoles(): array
    {
        return [[EmployeeRole::Owner], [EmployeeRole::Admin], [EmployeeRole::Manager]];
    }

    #[DataProvider('unscopedRoles')]
    public function test_owner_admin_and_existing_manager_queries_and_exports_remain_broad(EmployeeRole $role): void
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

    public function test_product_master_and_operational_search_are_not_globally_creator_scoped(): void
    {
        [$owner, $staff] = $this->users();
        $product = Product::factory()->create(['created_by_user_id' => $owner->id, 'name' => 'Unrelated operational master Product']);
        app(EmployeePermissionOverrideService::class)->change($staff->employee, PurchasePermission::Create->value,
            EmployeePermissionEffect::Allow, 'Operational purchase selection', $owner);
        $this->actingAs($staff);
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($product)->exists());
        $this->assertTrue(Product::query()->whereKey($product)->exists());
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData([
            'employee' => $staff->employee, 'brand' => $product->brandRelation,
        ]), $owner);
        $results = app(ProductMatchService::class)->match(new ProductMatchRequest($product->sku, ProductMatchContext::Purchase, $owner));
        $this->assertTrue($results->pluck('productId')->contains($product->id));
        $staffResults = app(ProductMatchService::class)->match(new ProductMatchRequest($product->sku, ProductMatchContext::Purchase, $staff,
            warehouseId: Warehouse::factory()->create()->id));
        $this->assertTrue($staffResults->pluck('productId')->contains($product->id));
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($product)->exists());
        $this->assertDatabaseCount('responsibility_assignments', 1);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
    }

    private function users(): array
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        foreach ([$owner, $staff, $other] as $user) {
            $email = 'creator-test-'.$user->id.'@techpointzone.com';
            $user->update(['email' => $email]);
            $user->employee->update(['email' => $email]);
        }
        foreach ([$staff, $other] as $user) {
            foreach ([ProductPermission::View, ProductPermission::Create, ProductPermission::Update, ProductPermission::Export,
                ProductPermission::ViewSellingPrice, ProductPermission::EditSellingPrice, ProductPermission::Activate,
                ProductPermission::Deactivate, ProductPermission::Discontinue, ProductPermission::Reactivate] as $permission) {
                app(EmployeePermissionOverrideService::class)->change($user->employee, $permission->value,
                    EmployeePermissionEffect::Allow, 'Approved Product creator regression access', $owner);
            }
        }

        return [$owner, $staff, $other];
    }

    private function updateData(Product $product): UpdateProductData
    {
        return new UpdateProductData(name: 'Updated own Product', brandId: $product->brand_id, categoryId: $product->category_id, condition: $product->condition);
    }

    private function csv(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
