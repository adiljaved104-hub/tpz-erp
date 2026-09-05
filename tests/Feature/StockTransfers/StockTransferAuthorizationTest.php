<?php

namespace Tests\Feature\StockTransfers;

use App\Actions\StockTransfers\CreateStockTransfer;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockTransferPermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\StockTransfers\StockTransferReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTransferAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_override_still_requires_matching_brand_and_marketplace_responsibility(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $platform = MarketplacePlatform::factory()->create();
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = Warehouse::factory()->create(['location_type' => InventoryLocationType::MarketplaceFulfilment, 'marketplace_platform_id' => $platform->id, 'status' => true]);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $source->id, 'available_quantity' => 5, 'reserved_quantity' => 0, 'average_cost' => '100.0000']);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $manager->employee->id, 'assigned_by_user_id' => $owner->id]);
        ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $brand->id, 'created_at' => now()]);
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $platform->id, 'created_at' => now()]);

        $transfer = $this->draft($manager, $source, $destination, $product);
        $this->assertFalse(app(StockTransferAuthorization::class)->allows($manager, StockTransferPermission::Dispatch, $transfer));
        EmployeePermissionOverride::query()->create(['employee_id' => $manager->employee->id, 'permission_key' => StockTransferPermission::Dispatch->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Dispatch role']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $this->assertTrue(app(StockTransferAuthorization::class)->allows($manager, StockTransferPermission::Dispatch, $transfer));
        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $manager);

        $wrongBrand = ProductBrand::factory()->create();
        $unrelated = Product::factory()->create(['brand_id' => $wrongBrand->id, 'brand' => $wrongBrand->name]);
        ProductInventory::factory()->create(['product_id' => $unrelated->id, 'warehouse_id' => $source->id, 'available_quantity' => 3, 'reserved_quantity' => 0, 'average_cost' => '50.0000']);
        $this->expectException(AuthorizationException::class);
        $this->draft($manager, $source, $destination, $unrelated);
    }

    public function test_scoped_read_query_returns_only_assigned_transfers_without_loading_cost_for_admin(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $admin = $this->user(EmployeeRole::Admin);
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = Warehouse::factory()->create(['status' => true]);
        $assignedBrand = ProductBrand::factory()->create();
        $otherBrand = ProductBrand::factory()->create();
        $assigned = Product::factory()->create(['brand_id' => $assignedBrand->id, 'brand' => $assignedBrand->name]);
        $other = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        foreach ([$assigned, $other] as $product) {
            ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $source->id, 'available_quantity' => 4, 'reserved_quantity' => 0, 'average_cost' => '90.0000']);
        }
        $assignedTransfer = $this->draft($owner, $source, $destination, $assigned);
        $this->draft($owner, $source, $destination, $other);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id, 'assigned_by_user_id' => $owner->id]);
        ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $assignedBrand->id, 'created_at' => now()]);
        EmployeePermissionOverride::query()->create(['employee_id' => $staff->employee->id, 'permission_key' => StockTransferPermission::View->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'View assigned transfers']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertSame([$assignedTransfer->id], app(StockTransferReadService::class)->query($staff)->pluck('stock_transfers.id')->all());

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        app(StockTransferReadService::class)->query($admin)->get();
        $itemQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "stock_transfer_items"'));
        $this->assertNotNull($itemQuery);
        $this->assertStringNotContainsString('dispatch_unit_cost', $itemQuery);
    }

    private function draft(User $actor, Warehouse $source, Warehouse $destination, Product $product)
    {
        return app(CreateStockTransfer::class)->handle(new CreateStockTransferData($source->id, $destination->id, today()->toDateString(), [new StockTransferItemData($product->id, 1)], (string) Str::uuid()), $actor);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
