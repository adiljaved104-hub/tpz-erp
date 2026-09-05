<?php

namespace Tests\Feature\Authorization;

use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Inventory\InventoryReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryResponsibilitySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_inventory_queries_require_an_active_matching_responsibility(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $assigned = ProductInventory::factory()->create(['average_cost' => '100.0000']);
        $unrelated = ProductInventory::factory()->create(['average_cost' => '900.0000']);
        $read = app(InventoryReadService::class);

        $this->assertSame([], $read->inventories($manager)->pluck('id')->all());
        $this->assertSame([], app(InventoryLocationOverviewService::class)->forUser($manager)->all());

        $assignment = $this->assignment($manager, ResponsibilityAssignmentStatus::Active);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $assigned->product_id]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->assertSame([$assigned->id], $read->inventories($manager)->pluck('id')->all());
        $inventorySql = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "product_inventories"'));
        $this->assertNotNull($inventorySql);
        $this->assertStringNotContainsString('average_cost', $inventorySql);
        $this->assertFalse($manager->can('view', $unrelated));

        $assignment->forceFill(['status' => ResponsibilityAssignmentStatus::Inactive, 'ended_at' => now()])->saveQuietly();
        $this->assertSame([], $read->inventories($manager)->pluck('id')->all());
    }

    public function test_product_plus_platform_scope_does_not_expose_the_same_product_at_an_unassigned_platform(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $product = Product::factory()->create();
        $amazon = MarketplacePlatform::factory()->create();
        $noon = MarketplacePlatform::factory()->create();
        $amazonLocation = Warehouse::factory()->create(['location_type' => InventoryLocationType::MarketplaceFulfilment, 'marketplace_platform_id' => $amazon->id]);
        $noonLocation = Warehouse::factory()->create(['location_type' => InventoryLocationType::MarketplaceFulfilment, 'marketplace_platform_id' => $noon->id]);
        $amazonInventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $amazonLocation->id]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $noonLocation->id]);
        $assignment = $this->assignment($manager, ResponsibilityAssignmentStatus::Active);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $amazon->id]);

        $this->assertSame([$amazonInventory->id], app(InventoryReadService::class)->inventories($manager)->pluck('id')->all());
    }

    private function assignment(User $user, ResponsibilityAssignmentStatus $status): ResponsibilityAssignment
    {
        return ResponsibilityAssignment::factory()->create([
            'employee_id' => $user->employee->id,
            'assigned_by_user_id' => $user->id,
            'status' => $status,
            'ended_at' => $status === ResponsibilityAssignmentStatus::Active ? null : now(),
        ]);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
