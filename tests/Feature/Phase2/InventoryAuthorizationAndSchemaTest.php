<?php

namespace Tests\Feature\Phase2;

use App\Actions\Inventory\PostOpeningStock;
use App\Actions\Warehouses\SetWarehouseStatus;
use App\Contracts\InventoryPermissionResolver;
use App\DTOs\Inventory\PostOpeningStockData;
use App\DTOs\Warehouses\ChangeWarehouseStatusData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Exceptions\WarehouseOperationalUseException;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAuthorizationAndSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_balance_and_database_quantity_constraints_are_enforced(): void
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id]);

        try {
            ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id]);
            $this->fail('Duplicate Product/Warehouse balance should fail.');
        } catch (QueryException) {
            $this->assertSame(1, ProductInventory::query()->count());
        }

        $this->expectException(QueryException::class);
        DB::table('product_inventories')->insert([
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 1,
            'reserved_quantity' => 2,
            'damaged_quantity' => 0,
        ]);
    }

    public function test_product_creation_does_not_create_inventory(): void
    {
        Product::factory()->create();
        $this->assertSame(0, ProductInventory::query()->count());
    }

    public function test_non_owner_projections_exclude_financial_columns(): void
    {
        [$owner, $product, $warehouse] = $this->foundation(EmployeeRole::Owner);
        app(PostOpeningStock::class)->handle(new PostOpeningStockData($product->id, $warehouse->id, 2, 0, '12.3400', 'Initial', (string) Str::uuid()), $owner);
        $admin = $this->user(EmployeeRole::Admin);

        $adminInventory = app(InventoryReadService::class)->inventories($admin)->firstOrFail();
        $adminMovement = app(InventoryReadService::class)->movements($admin)->firstOrFail();
        $ownerInventory = app(InventoryReadService::class)->inventories($owner)->firstOrFail();
        $ownerMovement = app(InventoryReadService::class)->movements($owner)->firstOrFail();

        $this->assertArrayNotHasKey('average_cost', $adminInventory->getAttributes());
        $this->assertArrayNotHasKey('unit_cost', $adminMovement->getAttributes());
        $this->assertArrayNotHasKey('average_cost_before', $adminMovement->getAttributes());
        $this->assertArrayNotHasKey('average_cost_after', $adminMovement->getAttributes());
        $this->assertArrayHasKey('average_cost', $ownerInventory->getAttributes());
        $this->assertArrayHasKey('unit_cost', $ownerMovement->getAttributes());
    }

    public function test_admin_cannot_post_opening_stock_and_staff_cannot_access_resource(): void
    {
        [$admin, $product, $warehouse] = $this->foundation(EmployeeRole::Admin);

        try {
            app(PostOpeningStock::class)->handle(new PostOpeningStockData($product->id, $warehouse->id, 1, 0, '1', 'Initial', (string) Str::uuid()), $admin);
            $this->fail('Admin should not post Opening Stock.');
        } catch (AuthorizationException) {
            $this->assertSame(0, ProductInventory::query()->count());
        }

        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->get(ProductInventoryResource::getUrl())->assertForbidden();
    }

    public function test_replaceable_permission_resolver_can_grant_staff_view_without_workflow_changes(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->app->bind(InventoryPermissionResolver::class, fn () => new class implements InventoryPermissionResolver
        {
            public function allows(User $user, InventoryPermission $permission, ?ProductInventory $inventory = null): bool
            {
                return $permission === InventoryPermission::View;
            }
        });

        $this->assertTrue($staff->can(InventoryPermission::View->value));
        $this->assertFalse($staff->can(InventoryPermission::ViewFinancials->value));
    }

    public function test_nonzero_inventory_blocks_warehouse_deactivation(): void
    {
        [$owner, $product, $warehouse] = $this->foundation(EmployeeRole::Owner);
        Warehouse::factory()->create();
        app(PostOpeningStock::class)->handle(new PostOpeningStockData($product->id, $warehouse->id, 1, 0, '1', 'Initial', (string) Str::uuid()), $owner);

        $this->expectException(WarehouseOperationalUseException::class);
        app(SetWarehouseStatus::class)->handle($warehouse, new ChangeWarehouseStatusData(false, 'Closing location'), $owner);
    }

    public function test_foreign_key_integrity_is_clean(): void
    {
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    /** @return array{User, Product, Warehouse} */
    private function foundation(EmployeeRole $role): array
    {
        return [$this->user($role), Product::factory()->create(), Warehouse::factory()->create()];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
