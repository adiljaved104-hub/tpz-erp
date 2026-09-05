<?php

namespace Tests\Feature\Returns;

use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Responsibilities\UpdateMarketplaceReturnConfiguration;
use App\Actions\StockTransfers\CreateStockTransfer;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Responsibilities\UpdateMarketplaceReturnConfigurationData;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Enums\StockMovementBucket;
use App\Exceptions\ImmutableInventoryRecordException;
use App\Exceptions\InsufficientInventoryException;
use App\Filament\Resources\MarketplacePlatforms\Pages\EditMarketplacePlatform;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\StockMovement;
use App\Models\StockMovementBucketChange;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLocationOverviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnFoundationPhase1Test extends TestCase
{
    use RefreshDatabase;

    public function test_platform_return_modes_are_nullable_and_have_business_labels(): void
    {
        $platform = MarketplacePlatform::factory()->create();

        $this->assertNull($platform->return_handling_mode);
        $this->assertSame([
            'Hold Non-Sellable at Marketplace',
            'Auto Return Non-Sellable to Company',
            'Return Directly to Company',
        ], array_map(fn (MarketplaceReturnHandlingMode $mode): string => $mode->getLabel(), MarketplaceReturnHandlingMode::cases()));
    }

    public function test_owner_and_admin_can_configure_a_platform_and_the_change_is_safely_audited(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $platform = MarketplacePlatform::factory()->create();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

        app(UpdateMarketplaceReturnConfiguration::class)->handle($platform, new UpdateMarketplaceReturnConfigurationData(
            MarketplaceReturnHandlingMode::HoldNonSellableAtMarketplace,
            $warehouse->id,
        ), $owner);
        app(UpdateMarketplaceReturnConfiguration::class)->handle($platform->refresh(), new UpdateMarketplaceReturnConfigurationData(
            MarketplaceReturnHandlingMode::AutoReturnNonSellableToCompany,
            $warehouse->id,
        ), $admin);

        $this->assertSame(MarketplaceReturnHandlingMode::AutoReturnNonSellableToCompany, $platform->refresh()->return_handling_mode);
        $this->assertTrue($platform->defaultReturnReceivingWarehouse->is($warehouse));
        $this->assertDatabaseCount('activity_logs', 2);
        $log = ActivityLog::query()->latest('id')->firstOrFail();
        $this->assertSame('marketplace_platform.return_configuration_updated', $log->event);
        $this->assertStringNotContainsString('cost', strtolower(json_encode($log->properties)));
        $this->assertStringNotContainsString('value', strtolower(json_encode($log->properties)));
    }

    public function test_manager_and_staff_cannot_configure_platform_returns(): void
    {
        $platform = MarketplacePlatform::factory()->create();

        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            try {
                app(UpdateMarketplaceReturnConfiguration::class)->handle($platform, new UpdateMarketplaceReturnConfigurationData(
                    MarketplaceReturnHandlingMode::ReturnDirectlyToCompany,
                    null,
                ), $this->user($role));
                $this->fail("{$role->value} should not configure Marketplace Returns.");
            } catch (AuthorizationException) {
                $this->assertNull($platform->refresh()->return_handling_mode);
            }
        }
    }

    public function test_transit_inactive_and_unknown_receiving_locations_are_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $platform = MarketplacePlatform::factory()->create();
        $transit = Warehouse::factory()->create(['location_type' => InventoryLocationType::Transit]);
        $inactive = Warehouse::factory()->inactive()->create();

        foreach ([$transit->id, $inactive->id, 999999] as $warehouseId) {
            try {
                app(UpdateMarketplaceReturnConfiguration::class)->handle($platform, new UpdateMarketplaceReturnConfigurationData(
                    MarketplaceReturnHandlingMode::HoldNonSellableAtMarketplace,
                    $warehouseId,
                ), $owner);
                $this->fail('An invalid receiving location should be rejected.');
            } catch (ValidationException) {
                $this->assertNull($platform->refresh()->default_return_receiving_warehouse_id);
            }
        }
    }

    public function test_platform_edit_form_uses_business_labels_and_saves_configuration(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $platform = MarketplacePlatform::factory()->create();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

        Livewire::actingAs($owner)
            ->test(EditMarketplacePlatform::class, ['record' => $platform->getRouteKey()])
            ->assertSee('Return Handling Mode')
            ->assertSee('Default Return Receiving Location')
            ->fillForm([
                'name' => $platform->name,
                'return_handling_mode' => MarketplaceReturnHandlingMode::HoldNonSellableAtMarketplace,
                'default_return_receiving_warehouse_id' => $warehouse->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(MarketplaceReturnHandlingMode::HoldNonSellableAtMarketplace, $platform->refresh()->return_handling_mode);
        $this->assertSame($warehouse->id, $platform->default_return_receiving_warehouse_id);
    }

    public function test_custody_defaults_formulas_and_database_constraints(): void
    {
        $inventory = ProductInventory::factory()->create([
            'available_quantity' => 12,
            'reserved_quantity' => 7,
            'damaged_quantity' => 2,
            'average_cost' => '100.0000',
        ]);

        $this->assertSame(0, $inventory->marketplace_non_sellable_quantity);
        $this->assertSame('0.0000', $inventory->marketplace_non_sellable_value);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertSame('0.0000', $inventory->qc_pending_value);
        $this->assertSame(5, $inventory->sellableQuantity());
        $this->assertSame(14, $inventory->totalOnHand());

        $inventory->forceFill([
            'marketplace_non_sellable_quantity' => 3,
            'marketplace_non_sellable_value' => '0.0000',
            'qc_pending_quantity' => 2,
            'qc_pending_value' => '40.0000',
        ])->save();
        $this->assertSame(19, $inventory->refresh()->locationTotalOwned());
        $this->assertSame(5, $inventory->sellableQuantity());
        $this->assertSame('100.0000', $inventory->average_cost);

        $this->assertDatabaseConstraintRejects($inventory, ['marketplace_non_sellable_quantity' => 0, 'marketplace_non_sellable_value' => '1.0000']);
        $this->assertDatabaseConstraintRejects($inventory, ['qc_pending_quantity' => 0, 'qc_pending_value' => '1.0000']);
        $this->assertDatabaseConstraintRejects($inventory, ['qc_pending_quantity' => -1, 'qc_pending_value' => '0.0000']);
    }

    public function test_inventory_overview_counts_custody_once_and_omits_financial_columns_for_unauthorized_users(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $inventory = ProductInventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 10,
            'reserved_quantity' => 3,
            'damaged_quantity' => 2,
            'average_cost' => '100.0000',
            'marketplace_non_sellable_quantity' => 4,
            'marketplace_non_sellable_value' => '360.0000',
            'qc_pending_quantity' => 1,
            'qc_pending_value' => '95.0000',
        ]);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $manager->employee->id,
            'assigned_by_user_id' => $manager->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create([
            'assignment_id' => $assignment->id,
            'product_id' => $inventory->product_id,
        ]);

        $ownerRow = app(InventoryLocationOverviewService::class)->forUser($owner)->sole();
        $this->assertSame(17, $ownerRow['total_owned']);
        $this->assertSame(4, $ownerRow['marketplace_non_sellable']);
        $this->assertSame(1, $ownerRow['qc_pending']);
        $this->assertSame('1655.0000', $ownerRow['total_inventory_value']);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $managerRow = app(InventoryLocationOverviewService::class)->forUser($manager)->sole();
        $inventoryQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "product_inventories"'));

        $this->assertSame(17, $managerRow['total_owned']);
        $this->assertArrayNotHasKey('inventory_value', $managerRow);
        $this->assertStringNotContainsString('average_cost', $inventoryQuery);
        $this->assertStringNotContainsString('marketplace_non_sellable_value', $inventoryQuery);
        $this->assertStringNotContainsString('qc_pending_value', $inventoryQuery);
        $this->assertArrayNotHasKey('average_cost', $inventory->toArray());
        $this->assertArrayNotHasKey('marketplace_non_sellable_value', $inventory->toArray());
    }

    public function test_orders_and_normal_stock_transfers_cannot_consume_custody_buckets(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = Warehouse::factory()->create(['code' => 'DEST']);
        $product = Product::factory()->create();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'average_cost' => null,
            'marketplace_non_sellable_quantity' => 5,
            'marketplace_non_sellable_value' => '500.0000',
            'qc_pending_quantity' => 3,
            'qc_pending_value' => '300.0000',
        ]);

        try {
            app(SaveAndReserveOrder::class)->handle(new SaveAndReserveOrderData(
                $source->id, null, null, today()->toDateString(), $owner->employee->id, null,
                [new OrderItemData($product->id, 1, '100.00')], (string) Str::uuid(),
            ), $owner);
            $this->fail('Custody inventory must not satisfy Order availability.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
        }

        $transfer = app(CreateStockTransfer::class)->handle(new CreateStockTransferData(
            $source->id, $destination->id, today()->toDateString(),
            [new StockTransferItemData($product->id, 1)], (string) Str::uuid(),
        ), $owner);
        try {
            app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $owner);
            $this->fail('Custody inventory must not satisfy normal Stock Transfer availability.');
        } catch (InsufficientInventoryException) {
            $inventory->refresh();
            $this->assertSame(5, $inventory->marketplace_non_sellable_quantity);
            $this->assertSame(3, $inventory->qc_pending_quantity);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_stock_movement_bucket_changes_are_constrained_and_immutable(): void
    {
        $movement = StockMovement::factory()->create();
        $change = StockMovementBucketChange::query()->create([
            'stock_movement_id' => $movement->id,
            'bucket' => StockMovementBucket::MarketplaceNonSellable,
            'quantity_delta' => 2,
            'quantity_before' => 0,
            'quantity_after' => 2,
            'value_delta' => '200.0000',
            'value_before' => '0.0000',
            'value_after' => '200.0000',
        ]);

        $this->assertSame(StockMovementBucket::MarketplaceNonSellable, $change->bucket);
        $this->assertTrue($movement->bucketChanges()->firstOrFail()->is($change));
        $this->assertArrayNotHasKey('value_after', $change->toArray());

        try {
            DB::table('stock_movement_bucket_changes')->insert([
                'stock_movement_id' => $movement->id,
                'bucket' => StockMovementBucket::QcPending->value,
                'quantity_delta' => -1,
                'quantity_before' => 0,
                'quantity_after' => -1,
                'value_delta' => '0.0000',
                'value_before' => '0.0000',
                'value_after' => '0.0000',
            ]);
            $this->fail('Invalid bucket history should be rejected by the database.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(ImmutableInventoryRecordException::class);
        $change->update(['quantity_after' => 3]);
    }

    private function assertDatabaseConstraintRejects(ProductInventory $inventory, array $values): void
    {
        try {
            DB::table('product_inventories')->where('id', $inventory->id)->update($values);
            $this->fail('The custody database constraint should reject invalid values.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
