<?php

namespace Tests\Feature\Components;

use App\Enums\ComponentPermission;
use App\Enums\EmployeeRole;
use App\Enums\InventoryItemType;
use App\Filament\Resources\Components\ComponentResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Component;
use App\Models\ComponentRecoveryValueEvent;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\ComponentAuthorization;
use App\Services\Components\ComponentCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComponentCatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_defaults_existing_products_and_enforces_component_constraints(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue(Schema::hasColumns('components', ['product_id', 'component_type', 'specification', 'approved_oem_recovery_value']));
        $this->assertTrue(Schema::hasTable('component_recovery_value_events'));
        $this->assertSame(InventoryItemType::Product, $product->refresh()->inventory_item_type);

        $this->expectException(\Throwable::class);
        DB::table('components')->insert([
            'product_id' => $product->id, 'component_type' => 'invalid', 'specification' => 'Invalid',
            'approved_oem_recovery_value' => -1, 'created_by_user_id' => 1, 'updated_by_user_id' => 1,
        ]);
    }

    public function test_owner_creates_component_and_recovery_approval_is_durably_audited(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $brand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();

        $component = app(ComponentCatalogService::class)->create([
            'name' => 'RAM 8GB DDR4 3200', 'brand_id' => $brand->id, 'category_id' => $category->id,
            'component_type' => 'ram', 'specification' => '8GB DDR4 3200',
            'capacity_value' => 8, 'capacity_unit' => 'gb', 'interface_type' => 'DDR4',
        ], $owner);
        $this->assertSame(InventoryItemType::Component, $component->product->inventory_item_type);
        $this->assertSame('0.0000', $component->approved_oem_recovery_value);

        $component = app(ComponentCatalogService::class)->updateApprovedRecoveryValue($component, '30.0000', 'Approved standard OEM recovery benchmark.', $owner);
        $this->assertSame('30.0000', $component->approved_oem_recovery_value);
        $this->assertSame($owner->id, $component->recovery_approved_by_user_id);
        $this->assertNotNull($component->recovery_approved_at);
        $this->assertDatabaseHas('component_recovery_value_events', [
            'component_id' => $component->id, 'old_value' => 0, 'new_value' => 30,
            'actor_user_id' => $owner->id,
        ]);
        $this->assertSame(1, ComponentRecoveryValueEvent::query()->count());
    }

    public function test_admin_can_manage_catalog_but_cannot_view_or_approve_recovery_value(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $component = Component::factory()->create();
        $authorization = app(ComponentAuthorization::class);

        $this->assertTrue($authorization->allows($admin, ComponentPermission::View));
        $this->assertTrue($authorization->allows($admin, ComponentPermission::Update));
        $this->assertFalse($authorization->allows($admin, ComponentPermission::ViewRecoveryValue));
        $this->assertFalse($authorization->allows($admin, ComponentPermission::ApproveRecoveryValue));

        $this->expectException(AuthorizationException::class);
        app(ComponentCatalogService::class)->updateApprovedRecoveryValue($component, '25.0000', 'Admin should not approve.', $admin);
    }

    public function test_component_query_omits_financial_fields_and_products_resource_excludes_components(): void
    {
        Product::factory()->create();
        $component = Component::factory()->create(['approved_oem_recovery_value' => '0.0000']);
        $admin = $this->user(EmployeeRole::Admin);
        $this->actingAs($admin);

        $row = ComponentResource::getEloquentQuery()->findOrFail($component->id);
        $this->assertArrayNotHasKey('approved_oem_recovery_value', $row->getAttributes());
        $this->assertArrayNotHasKey('component_average_cost', $row->getAttributes());
        $this->assertFalse(ProductResource::getEloquentQuery()->whereKey($component->product_id)->exists());

        $owner = $this->user(EmployeeRole::Owner);
        $this->actingAs($owner);
        $ownerRow = ComponentResource::getEloquentQuery()->findOrFail($component->id);
        $this->assertArrayHasKey('approved_oem_recovery_value', $ownerRow->getAttributes());
        $this->assertArrayHasKey('component_average_cost', $ownerRow->getAttributes());
    }

    public function test_access_control_registry_reconciles_component_permissions(): void
    {
        $result = app(AccessControlModuleRegistry::class)->reconcile();

        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['unknown']);
        $this->assertSame([], $result['duplicates']);
    }

    public function test_authorized_component_catalog_pages_render(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        Component::factory()->create();

        $this->actingAs($owner)->get(ComponentResource::getUrl('index'))->assertOk()->assertSee('Upgrade Components');
        $this->actingAs($owner)->get(ComponentResource::getUrl('create'))->assertOk()->assertSee('Component Catalog');
    }

    public function test_admin_component_page_does_not_render_recovery_or_average_cost_fields(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        Component::factory()->create();

        $this->actingAs($admin)->get(ComponentResource::getUrl('index'))
            ->assertOk()
            ->assertDontSee('Approved OEM Recovery')
            ->assertDontSee('Average Cost');
    }

    private function user(EmployeeRole $role): User
    {
        $email = $role === EmployeeRole::Owner
            ? 'component-owner-'.Str::lower(Str::random(8)).'@example.com'
            : 'component-'.Str::lower(Str::random(8)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
