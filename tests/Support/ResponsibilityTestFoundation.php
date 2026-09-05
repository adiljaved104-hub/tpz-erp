<?php

namespace Tests\Support;

use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentMode;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

trait ResponsibilityTestFoundation
{
    protected function responsibilityUser(EmployeeRole $role, ?Team $team = null): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $team?->id]);

        return $user->refresh();
    }

    /** @return array{owner: User, employee: Employee, brand: ProductBrand, platform: MarketplacePlatform, product: Product, inventory: ProductInventory} */
    protected function responsibilityFoundation(int $available = 20, int $reserved = 0): array
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $employeeUser = $this->responsibilityUser(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE', 'normalized_name' => 'amazon uae', 'code' => 'amazon_uae']);
        $product = Product::factory()->create(['brand' => 'HP', 'brand_id' => $brand->id]);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => $available, 'reserved_quantity' => $reserved, 'average_cost' => '125.0000']);

        return compact('owner', 'brand', 'platform', 'product', 'inventory') + ['employee' => $employeeUser->employee];
    }

    protected function assignmentData(array $foundation, ResponsibilityAssignmentMode $mode = ResponsibilityAssignmentMode::Scope, array $overrides = []): CreateResponsibilityAssignmentData
    {
        $values = array_merge([
            'employeeId' => $foundation['employee']->id,
            'mode' => $mode,
            'brandId' => $mode === ResponsibilityAssignmentMode::Scope ? $foundation['brand']->id : null,
            'platformId' => null,
            'productId' => null,
            'productInventoryId' => $mode === ResponsibilityAssignmentMode::Quantity ? $foundation['inventory']->id : null,
            'assignedQuantity' => $mode === ResponsibilityAssignmentMode::Quantity ? 1 : null,
            'effectiveAt' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Operational responsibility',
            'notes' => null,
            'idempotencyKey' => (string) Str::uuid(),
        ], $overrides);

        return new CreateResponsibilityAssignmentData(...$values);
    }
}
