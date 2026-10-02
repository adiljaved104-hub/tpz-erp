<?php

namespace Tests\Feature\Inventory;

use App\Contracts\InventoryPermissionResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Inventory\InventoryReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocationBalancesVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_with_required_access_sees_only_responsibility_scoped_inventory_on_both_screens(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $insideBrand = ProductBrand::factory()->create();
        $outsideBrand = ProductBrand::factory()->create();
        $inside = Product::factory()->create(['brand_id' => $insideBrand->id]);
        $outside = Product::factory()->create(['brand_id' => $outsideBrand->id]);
        $warehouse = Warehouse::factory()->create();
        $insideInventory = ProductInventory::factory()->create([
            'product_id' => $inside->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 4,
            'average_cost' => '125.0000',
        ]);
        $outsideInventory = ProductInventory::factory()->create([
            'product_id' => $outside->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 7,
            'average_cost' => '250.0000',
        ]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        ResponsibilityAssignmentBrand::query()->create([
            'assignment_id' => $assignment->id,
            'product_brand_id' => $insideBrand->id,
        ]);
        $this->allow($owner, $staff, InventoryPermission::View->value);
        $this->allow($owner, $staff, InventoryPermission::ViewLocationBalances->value);
        $this->allow($owner, $staff, InventoryLocationPermission::View->value);

        $this->actingAs($staff->fresh());

        $this->assertTrue(ProductInventoryResource::canViewAny());
        Livewire::test(ListProductInventories::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$insideInventory])
            ->assertCanNotSeeTableRecords([$outsideInventory]);
        Livewire::test(InventoryOverview::class)
            ->assertOk()
            ->assertSee($inside->sku)
            ->assertDontSee($outside->sku);
    }

    public function test_record_policy_uses_page_permissions_then_the_same_responsibility_scope_as_the_resource_query(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id]);
        ResponsibilityAssignmentBrand::query()->create([
            'assignment_id' => $assignment->id,
            'product_brand_id' => $brand->id,
        ]);
        $this->app->bind(InventoryPermissionResolver::class, fn () => new class implements InventoryPermissionResolver
        {
            public function allows(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): bool
            {
                return $inventory === null
                    && in_array($permission, [InventoryPermission::View, InventoryPermission::ViewLocationBalances], true);
            }
        });
        $this->actingAs($staff);

        $this->assertSame([$inventory->id], ProductInventoryResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue($staff->can('viewAny', ProductInventory::class));
        $this->assertTrue($staff->can('view', $inventory));
    }

    public function test_location_permission_is_still_required_independently(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->allow($owner, $staff, InventoryPermission::View->value);
        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            InventoryPermission::ViewLocationBalances->value,
            EmployeePermissionEffect::Deny,
            'Focused Location Balances denial test',
            $owner,
        );
        $this->actingAs($staff->fresh());

        $this->assertFalse(ProductInventoryResource::canViewAny());
        Livewire::test(ListProductInventories::class)->assertForbidden();
    }

    public function test_owner_and_admin_keep_all_rows_while_cost_projection_remains_owner_only(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'average_cost' => '125.0000',
        ]);
        ProductInventory::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'average_cost' => '250.0000',
        ]);

        $this->actingAs($owner);
        $this->assertCount(2, ProductInventoryResource::getEloquentQuery()->get());
        $this->assertArrayHasKey('average_cost', app(InventoryReadService::class)->inventories($owner)->firstOrFail()->getAttributes());

        $this->actingAs($admin);
        $this->assertCount(2, ProductInventoryResource::getEloquentQuery()->get());
        $this->assertArrayNotHasKey('average_cost', app(InventoryReadService::class)->inventories($admin)->firstOrFail()->getAttributes());
    }

    private function allow(User $owner, User $employee, string $permission): void
    {
        app(EmployeePermissionOverrideService::class)->change(
            $employee->employee,
            $permission,
            EmployeePermissionEffect::Allow,
            'Focused Location Balances visibility test',
            $owner,
        );
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
