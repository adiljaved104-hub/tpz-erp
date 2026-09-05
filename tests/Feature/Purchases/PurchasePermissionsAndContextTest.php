<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Enums\PurchasePermission;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseProductContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasePermissionsAndContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_role_permissions_match_the_approved_matrix(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);
        $authorization = app(PurchaseAuthorization::class);

        foreach (PurchasePermission::cases() as $permission) {
            $this->assertTrue($authorization->allows($owner, $permission));
        }

        $this->assertTrue($authorization->allows($admin, PurchasePermission::Receive));
        $this->assertTrue($authorization->allows($admin, PurchasePermission::ViewCostHistory));
        $this->assertFalse($authorization->allows($admin, PurchasePermission::Approve));
        $this->assertTrue($authorization->allows($manager, PurchasePermission::Create));
        $this->assertFalse($authorization->allows($manager, PurchasePermission::Receive));
        $this->assertTrue($authorization->allows($manager, PurchasePermission::ViewCostHistory));
        $this->assertFalse($authorization->allows($staff, PurchasePermission::View));
        $this->assertFalse($authorization->allows($staff, PurchasePermission::ViewCostHistory));
    }

    public function test_financial_context_is_projected_only_for_authorized_roles(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['cost_price' => '451.0000']);
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 2,
            'damaged_quantity' => 1,
            'average_cost' => '100.0000',
        ]);

        $ownerContext = app(PurchaseProductContextService::class)->forProducts($owner, $warehouse->id, [$product->id])[$product->id];
        $adminContext = app(PurchaseProductContextService::class)->forProducts($admin, $warehouse->id, [$product->id])[$product->id];

        $this->assertSame('100.0000', $ownerContext->inventoryAverageCost);
        $this->assertSame('300.0000', $ownerContext->inventoryValue);
        $this->assertSame('451.0000', $ownerContext->productCostPrice);
        $this->assertNull($adminContext->inventoryAverageCost);
        $this->assertNull($adminContext->inventoryValue);
        $this->assertNull($adminContext->productCostPrice);
    }

    public function test_manager_financial_permission_is_limited_to_owned_draft_context(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $purchase = Purchase::factory()->for($manager, 'creator')->create();
        $authorization = app(PurchaseAuthorization::class);

        $this->assertTrue($authorization->allows($manager, PurchasePermission::ViewFinancials, $purchase));
        $this->assertFalse($authorization->allows($manager, PurchasePermission::ViewFinancials));
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }
}
