<?php

namespace Tests\Feature\InventoryLocations;

use App\Actions\Warehouses\CreateWarehouse;
use App\DTOs\Warehouses\CreateWarehouseData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryLocationType;
use App\Filament\Pages\Administration\AccessControl;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\InventoryLocationAuthorization;
use App\Services\Inventory\InventoryLocationOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryLocationPhaseATest extends TestCase
{
    use RefreshDatabase;

    public function test_main_is_classified_without_changing_its_identity_or_default_state(): void
    {
        $main = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

        $this->assertSame('Main Warehouse', $main->name);
        $this->assertSame(InventoryLocationType::CompanyWarehouse, $main->location_type);
        $this->assertTrue($main->status);
        $this->assertTrue($main->is_default);
        $this->assertNull($main->marketplace_platform_id);
        $this->assertNull($main->fulfillment_tag);
    }

    public function test_marketplace_location_requires_a_platform_and_non_marketplace_metadata_is_cleared(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        try {
            app(CreateWarehouse::class)->handle(new CreateWarehouseData(
                'Amazon Stock',
                'AMZ',
                locationType: InventoryLocationType::MarketplaceFulfilment,
            ), $owner);
            $this->fail('Marketplace fulfilment must require a platform.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('warehouses', ['code' => 'AMZ']);
        }

        $platform = MarketplacePlatform::factory()->create();
        $location = app(CreateWarehouse::class)->handle(new CreateWarehouseData(
            'Amazon Stock',
            'AMZ',
            locationType: InventoryLocationType::MarketplaceFulfilment,
            marketplacePlatformId: $platform->id,
            fulfillmentTag: 'FBA-UAE',
        ), $owner);

        $this->assertSame($platform->id, $location->marketplace_platform_id);
        $this->assertSame('FBA-UAE', $location->fulfillment_tag);

        $company = app(CreateWarehouse::class)->handle(new CreateWarehouseData(
            'Secondary',
            'SECONDARY',
            locationType: InventoryLocationType::CompanyWarehouse,
            marketplacePlatformId: $platform->id,
            fulfillmentTag: 'IGNORED',
        ), $owner);
        $this->assertNull($company->marketplace_platform_id);
        $this->assertNull($company->fulfillment_tag);
    }

    public function test_role_defaults_and_owner_protection_follow_the_centralized_resolver(): void
    {
        $authorization = app(InventoryLocationAuthorization::class);

        $this->assertTrue($authorization->allows($this->user(EmployeeRole::Owner), InventoryLocationPermission::Manage));
        $this->assertTrue($authorization->allows($this->user(EmployeeRole::Admin), InventoryLocationPermission::Manage));
        $this->assertTrue($authorization->allows($this->user(EmployeeRole::Manager), InventoryLocationPermission::View));
        $this->assertFalse($authorization->allows($this->user(EmployeeRole::Manager), InventoryLocationPermission::Manage));
        $this->assertFalse($authorization->allows($this->user(EmployeeRole::Staff), InventoryLocationPermission::View));
    }

    public function test_access_control_exposes_inventory_location_permissions_without_writing_overrides(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->user(EmployeeRole::Staff);

        Livewire::actingAs($owner)
            ->test(AccessControl::class)
            ->call('selectGroup', 'Inventory Locations')
            ->assertSet('group', 'Inventory Locations')
            ->assertSee('View Locations')
            ->assertSee('Manage Locations')
            ->assertDontSee('Ship Orders');

        $this->assertDatabaseCount('employee_permission_overrides', 0);
    }

    public function test_overview_aggregates_locations_and_omits_average_cost_from_unauthorized_sql(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $product = Product::factory()->create();
        $main = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $main->id,
            'available_quantity' => 10,
            'reserved_quantity' => 3,
            'damaged_quantity' => 2,
            'average_cost' => '1250.1234',
        ]);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $manager->employee->id,
            'assigned_by_user_id' => $manager->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create([
            'assignment_id' => $assignment->id,
            'product_id' => $inventory->product_id,
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $row = app(InventoryLocationOverviewService::class)->forUser($manager)->first();

        $this->assertSame(10, $row['available']);
        $this->assertSame(3, $row['reserved']);
        $this->assertSame(7, $row['sellable']);
        $this->assertSame(2, $row['damaged']);
        $this->assertSame(12, $row['total_on_hand']);
        $this->assertArrayNotHasKey('inventory_value', $row);
        $inventoryQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "product_inventories"'));
        $this->assertNotNull($inventoryQuery);
        $this->assertStringNotContainsString('average_cost', $inventoryQuery);
        $this->assertSame(0, $row['return_in_transit']);
        $this->assertSame(0, app(InventoryLocationOverviewService::class)->summaryForUser($manager)['return_in_transit']);
        Livewire::actingAs($manager)->test(InventoryOverview::class)->assertOk()->assertSee('Return-to-Company In Transit');

        $owner = $this->user(EmployeeRole::Owner);
        $ownerRow = app(InventoryLocationOverviewService::class)->forUser($owner)->first();
        $this->assertSame(0, $ownerRow['return_in_transit']);
        $this->assertArrayHasKey('return_in_transit_value', $ownerRow);
    }

    public function test_empty_scoped_inventory_distinguishes_company_stock_from_an_empty_company(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        ProductInventory::factory()->create();

        Livewire::actingAs($manager)
            ->test(InventoryOverview::class)
            ->assertSee('No inventory is available in your authorized Responsibility scope.')
            ->assertDontSee('No inventory balances exist yet.');
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
