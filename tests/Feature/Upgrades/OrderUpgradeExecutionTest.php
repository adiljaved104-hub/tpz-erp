<?php

namespace Tests\Feature\Upgrades;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\ComponentType;
use App\Enums\EmployeeRole;
use App\Enums\HardwareSubsystem;
use App\Enums\InventoryReservationKind;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductStatus;
use App\Enums\RecoveryValuationMethod;
use App\Enums\UpgradeRecipeOperation;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Exceptions\ImmutableOrderException;
use App\Models\Component;
use App\Models\Employee;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderItemUpgradeSelection;
use App\Models\OrderUpgradeExecution;
use App\Models\Product;
use App\Models\ProductHardwareProfile;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\SalesConfiguration;
use App\Models\StockMovement;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderUpgradeReadService;
use App\Services\Orders\WebSalesReadService;
use App\Services\Orders\WebSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderUpgradeExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_product_can_have_base_and_multiple_configured_lines_with_independent_reservations_and_cogs(): void
    {
        [$owner, $warehouse, $product, $baseInventory] = $this->baseFoundation();
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4', 8, 'gb', 'DDR4', 10, '45.0000');
        $ram16 = $this->fixtureComponent($warehouse, ComponentType::Ram, '16GB DDR4', 16, 'gb', 'DDR4', 10, '80.0000');
        $profile = $this->profile($product, $owner, [
            $this->slot(HardwareSubsystem::Ram, 'RAM-1', true, $ram8),
            $this->slot(HardwareSubsystem::Ram, 'RAM-2', false),
        ]);
        [$config16, $recipe16] = $this->ramConfiguration($profile, $owner, 16384, $ram8, '16GB RAM', '20.0000');
        [$config24, $recipe24] = $this->ramConfiguration($profile, $owner, 24576, $ram16, '24GB RAM', '20.0000');

        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1200.00'),
            new OrderItemData($product->id, 2, '1450.00', salesConfigurationId: $config16->id, upgradeRecipeId: $recipe16->id),
            new OrderItemData($product->id, 1, '1600.00', salesConfigurationId: $config24->id, upgradeRecipeId: $recipe24->id),
        ]), $owner);

        $items = $order->items()->with(['reservations', 'upgradeSelection'])->get();
        $this->assertSame([1, 2, 3], $items->pluck('line_number')->all());
        $this->assertCount(3, $items->pluck('line_key')->unique());
        $this->assertSame([$product->id, $product->id, $product->id], $items->pluck('product_id')->all());
        $this->assertSame(2, OrderItemUpgradeSelection::query()->count());
        $this->assertSame(3, InventoryReservation::query()->where('reservation_kind', InventoryReservationKind::BaseProduct)->count());
        $this->assertSame(2, InventoryReservation::query()->where('reservation_kind', InventoryReservationKind::UpgradeComponent)->count());
        $this->assertSame(4, $baseInventory->refresh()->reserved_quantity);
        $this->assertSame(2, $ram8->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail()->reserved_quantity);
        $this->assertSame(1, $ram16->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail()->reserved_quantity);

        app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $owner);

        $executions = OrderUpgradeExecution::query()->orderBy('id')->get();
        $this->assertCount(2, $executions);
        $this->assertSame('2130.0000', $executions[0]->final_configured_cogs);
        $this->assertSame('1100.0000', $executions[1]->final_configured_cogs);
        $this->assertSame(6, $baseInventory->refresh()->available_quantity);
        $this->assertSame(0, $baseInventory->reserved_quantity);
        $this->assertSame(0, InventoryReservation::query()->where('status', InventoryReservationStatus::Active)->count());
    }

    public function test_removed_component_recovery_updates_weighted_average_and_configured_cogs(): void
    {
        [$owner, $warehouse, $product] = $this->baseFoundation();
        $ssd256 = $this->fixtureComponent($warehouse, ComponentType::Ssd, '256GB NVMe', 256, 'gb', 'NVMe', 2, '50.0000', '35.0000', $owner);
        $ssd512 = $this->fixtureComponent($warehouse, ComponentType::Ssd, '512GB NVMe', 512, 'gb', 'NVMe', 5, '100.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Storage, 'M2-1', true, $ssd256, 'NVMe')]);
        $configuration = SalesConfiguration::query()->create($this->configuration($profile, $owner, '512GB SSD', ['target_storage_total_gb' => 512]));
        $recipe = UpgradeRecipe::query()->create($this->recipe($configuration, $owner, 'SSD replacement', '10.0000'));
        $recipe->lines()->create($this->line(1, UpgradeRecipeOperation::RemoveAndReturn, source: 'M2-1', recovered: $ssd256, recovery: RecoveryValuationMethod::CentralApproved));
        $recipe->lines()->create($this->line(2, UpgradeRecipeOperation::Install, target: 'M2-1', install: $ssd512));

        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1500.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);
        $fulfillmentKey = (string) Str::uuid();
        app(FulfillOrder::class)->handle($order, $fulfillmentKey, $owner);
        $movementCount = StockMovement::query()->count();
        app(FulfillOrder::class)->handle($order->refresh(), $fulfillmentKey, $owner);

        $execution = OrderUpgradeExecution::query()->sole();
        $this->assertSame('1000.0000', $execution->base_cogs);
        $this->assertSame('100.0000', $execution->installed_component_cost);
        $this->assertSame('35.0000', $execution->recovery_credit);
        $this->assertSame('10.0000', $execution->labour_cost);
        $this->assertSame('1075.0000', $execution->final_configured_cogs);
        $recovered = $ssd256->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame(3, $recovered->available_quantity);
        $this->assertSame('45.0000', $recovered->average_cost);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'upgrade_component_recovery', 'unit_cost' => 35]);
    }

    public function test_cancellation_releases_base_and_component_reservations_without_execution_or_recovery(): void
    {
        [$owner, $warehouse, $product, $baseInventory] = $this->baseFoundation();
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4', 8, 'gb', 'DDR4', 3, '45.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Ram, 'RAM-1', false)]);
        [$configuration, $recipe] = $this->ramConfiguration($profile, $owner, 8192, $ram8, '8GB RAM');
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 2, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);

        app(CancelOrder::class)->handle($order, new CancelOrderData('Customer cancelled configured order', (string) Str::uuid()), $owner);

        $this->assertSame(0, $baseInventory->refresh()->reserved_quantity);
        $this->assertSame(0, $ram8->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail()->reserved_quantity);
        $this->assertSame(0, InventoryReservation::query()->where('status', InventoryReservationStatus::Active)->count());
        $this->assertSame(0, OrderUpgradeExecution::query()->count());
        $this->assertSame(0, StockMovement::query()->whereIn('movement_type', ['upgrade_component_install', 'upgrade_component_recovery'])->count());
    }

    public function test_insufficient_component_stock_rolls_back_entire_order_and_competing_order_cannot_oversell(): void
    {
        [$owner, $warehouse, $product, $baseInventory] = $this->baseFoundation();
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4', 8, 'gb', 'DDR4', 1, '45.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Ram, 'RAM-1', false)]);
        [$configuration, $recipe] = $this->ramConfiguration($profile, $owner, 8192, $ram8, '8GB RAM');
        $first = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);

        try {
            app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
                new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
            ]), $owner);
            $this->fail('A competing component reservation should fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('required: 1; available: 0', collect($exception->errors())->flatten()->join(' '));
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($first->id, Order::query()->sole()->id);
        $this->assertSame(1, $baseInventory->refresh()->reserved_quantity);
        $this->assertSame(1, $ram8->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail()->reserved_quantity);
    }

    public function test_duplicate_fulfillment_is_idempotent_and_does_not_double_consume_or_recover(): void
    {
        [$owner, $warehouse, $product] = $this->baseFoundation();
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4', 8, 'gb', 'DDR4', 2, '45.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Ram, 'RAM-1', false)]);
        [$configuration, $recipe] = $this->ramConfiguration($profile, $owner, 8192, $ram8, '8GB RAM');
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);
        $key = (string) Str::uuid();

        app(FulfillOrder::class)->handle($order, $key, $owner);
        $movementCount = StockMovement::query()->count();
        app(FulfillOrder::class)->handle($order->refresh(), $key, $owner);

        $this->assertSame(1, OrderUpgradeExecution::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame(1, $ram8->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail()->available_quantity);
    }

    public function test_upgrade_read_projection_omits_financial_fields_for_staff_and_includes_them_for_owner(): void
    {
        [$owner, $warehouse, $product] = $this->baseFoundation();
        $staff = $this->user(EmployeeRole::Staff);
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4', 8, 'gb', 'DDR4', 2, '45.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Ram, 'RAM-1', false)]);
        [$configuration, $recipe] = $this->ramConfiguration($profile, $owner, 8192, $ram8, '8GB RAM');
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->employee->id,
            'team_id_at_assignment' => $staff->employee->team_id,
            'team_name_at_assignment' => $staff->employee->team?->name,
            'assigned_by_user_id' => $owner->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create([
            'assignment_id' => $assignment->id,
            'product_id' => $product->id,
        ]);

        $staffSql = app(OrderUpgradeReadService::class)->forOrder($order, $staff)->toSql();
        $ownerSql = app(OrderUpgradeReadService::class)->forOrder($order, $owner)->toSql();
        $this->assertStringNotContainsString('labour_cost', $staffSql);
        $this->assertStringNotContainsString('recovery_credit', $staffSql);
        $this->assertStringNotContainsString('final_configured_cogs', $staffSql);
        $this->assertStringContainsString('final_configured_cogs', $ownerSql);
    }

    public function test_save_and_reserve_retry_is_idempotent_for_base_and_component_reservations(): void
    {
        [$owner, $warehouse, $product, $baseInventory] = $this->baseFoundation();
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4', 8, 'gb', 'DDR4', 3, '45.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Ram, 'RAM-1', false)]);
        [$configuration, $recipe] = $this->ramConfiguration($profile, $owner, 8192, $ram8, '8GB RAM');
        $data = $this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]);

        $first = app(SaveAndReserveOrder::class)->handle($data, $owner);
        $reservationCount = InventoryReservation::query()->count();
        $movementCount = StockMovement::query()->count();
        $second = app(SaveAndReserveOrder::class)->handle($data, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, $reservationCount);
        $this->assertSame($reservationCount, InventoryReservation::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame(1, $baseInventory->refresh()->reserved_quantity);
        $this->assertSame(1, $ram8->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail()->reserved_quantity);
    }

    public function test_upgrade_selection_snapshots_are_immutable_when_catalog_values_change(): void
    {
        [$owner, $warehouse, $product] = $this->baseFoundation();
        $oldSsd = $this->fixtureComponent($warehouse, ComponentType::Ssd, '256GB OEM Snapshot', 256, 'gb', 'NVMe', 0, '0.0000', '30.0000', $owner);
        $newSsd = $this->fixtureComponent($warehouse, ComponentType::Ssd, '512GB Snapshot', 512, 'gb', 'NVMe', 2, '90.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Storage, 'M2-1', true, $oldSsd, 'NVMe')]);
        [$configuration, $recipe] = $this->storageReplacement($profile, $owner, $oldSsd, $newSsd, UpgradeRecipeOperation::RemoveAndReturn, '512GB Snapshot');
        $recipe->forceFill(['labour_unit_cost' => '12.0000'])->save();
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);
        $selection = $order->items()->firstOrFail()->upgradeSelection;
        $snapshots = $selection->only([
            'hardware_profile_version', 'configuration_snapshot', 'recipe_snapshot',
            'suggested_selling_addon_snapshot', 'labour_cost_snapshot', 'recovery_snapshot',
        ]);

        DB::table('sales_configurations')->where('id', $configuration->id)->update(['display_name' => 'Changed later', 'suggested_selling_addon' => 999]);
        DB::table('upgrade_recipes')->where('id', $recipe->id)->update(['name' => 'Changed recipe', 'labour_unit_cost' => 999]);
        DB::table('components')->where('id', $oldSsd->id)->update(['approved_oem_recovery_value' => 999]);

        $this->assertSame($snapshots, $selection->fresh()->only(array_keys($snapshots)));
        $this->assertSame('30.0000', $selection->recovery_snapshot['lines'][0]['approved_unit_value']);
        $this->expectException(ImmutableOrderException::class);
        $selection->forceFill(['labour_cost_snapshot' => '999.0000'])->save();
    }

    public function test_mid_execution_component_failure_rolls_back_base_fulfillment_and_all_upgrade_postings(): void
    {
        [$owner, $warehouse, $product, $baseInventory] = $this->baseFoundation();
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4', 8, 'gb', 'DDR4', 2, '45.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Ram, 'RAM-1', false)]);
        [$configuration, $recipe] = $this->ramConfiguration($profile, $owner, 8192, $ram8, '8GB RAM');
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);
        $componentInventory = $ram8->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail();
        $movementCount = StockMovement::query()->count();
        $componentInventory->forceFill(['average_cost' => null])->save();

        try {
            app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $owner);
            $this->fail('Fulfillment should fail when the reserved Component has no execution cost.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no available costed inventory', collect($exception->errors())->flatten()->join(' '));
        }

        $this->assertSame('reserved', $order->refresh()->status->value);
        $this->assertSame(10, $baseInventory->refresh()->available_quantity);
        $this->assertSame(1, $baseInventory->reserved_quantity);
        $this->assertSame(2, $componentInventory->refresh()->available_quantity);
        $this->assertSame(1, $componentInventory->reserved_quantity);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame(0, OrderFulfillment::query()->count());
        $this->assertSame(0, OrderUpgradeExecution::query()->count());
    }

    public function test_damaged_recovery_and_discard_have_distinct_inventory_and_execution_results(): void
    {
        [$owner, $warehouse, $product] = $this->baseFoundation();
        $oldSsd = $this->fixtureComponent($warehouse, ComponentType::Ssd, '256GB NVMe OEM', 256, 'gb', 'NVMe', 0, '0.0000', '35.0000', $owner);
        $newSsd = $this->fixtureComponent($warehouse, ComponentType::Ssd, '512GB NVMe', 512, 'gb', 'NVMe', 4, '100.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Storage, 'M2-1', true, $oldSsd, 'NVMe')]);
        [$damagedConfiguration, $damagedRecipe] = $this->storageReplacement($profile, $owner, $oldSsd, $newSsd, UpgradeRecipeOperation::RemoveAsDamaged, 'Damaged OEM recovery');
        [$discardConfiguration, $discardRecipe] = $this->storageReplacement($profile, $owner, $oldSsd, $newSsd, UpgradeRecipeOperation::RemoveAndDiscard, 'Discard OEM component');
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1500.00', salesConfigurationId: $damagedConfiguration->id, upgradeRecipeId: $damagedRecipe->id),
            new OrderItemData($product->id, 1, '1500.00', salesConfigurationId: $discardConfiguration->id, upgradeRecipeId: $discardRecipe->id),
        ]), $owner);
        $this->assertSame(2, InventoryReservation::query()->where('reservation_kind', InventoryReservationKind::UpgradeComponent)->count());

        app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $owner);

        $recovered = $oldSsd->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame(0, $recovered->available_quantity);
        $this->assertSame(1, $recovered->damaged_quantity);
        $this->assertSame('35.0000', $recovered->average_cost);
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'upgrade_component_recovery_damaged')->count());
        $discardLine = OrderUpgradeExecution::query()->whereHas('selection', fn ($query) => $query->where('sales_configuration_id', $discardConfiguration->id))
            ->firstOrFail()->lines()->where('operation', UpgradeRecipeOperation::RemoveAndDiscard)->firstOrFail();
        $this->assertNull($discardLine->stock_movement_id);
        $this->assertSame('0.0000', $discardLine->total_value);
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'upgrade_component_recovery')->count());
    }

    public function test_recovery_credit_is_zero_when_unknown_and_is_capped_at_base_cogs_when_approved_value_is_higher(): void
    {
        foreach ([['Unknown recovery', '0.0000', '0.0000'], ['Capped recovery', '1500.0000', '1000.0000']] as [$name, $approvedValue, $expectedCredit]) {
            [$owner, $warehouse, $product] = $this->baseFoundation();
            $old = $this->fixtureComponent($warehouse, ComponentType::Ssd, $name, 256, 'gb', 'NVMe', 0, '0.0000', $approvedValue, $owner);
            $newSsd = $this->fixtureComponent($warehouse, ComponentType::Ssd, '512GB NVMe '.$name, 512, 'gb', 'NVMe', 2, '100.0000');
            $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Storage, 'M2-1', true, $old, 'NVMe')]);
            [$configuration, $recipe] = $this->storageReplacement($profile, $owner, $old, $newSsd, UpgradeRecipeOperation::RemoveAndReturn, $name);
            $order = app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
                new OrderItemData($product->id, 1, '1500.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
            ]), $owner);
            app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $owner);
            $execution = $order->refresh()->items()->firstOrFail()->upgradeSelection->execution;
            $this->assertSame($expectedCredit, $execution->recovery_credit);
            $this->assertGreaterThanOrEqual(0, (float) $execution->final_configured_cogs);
            $line = $execution->lines()->where('operation', UpgradeRecipeOperation::RemoveAndReturn)->firstOrFail();
            $this->assertSame($expectedCredit, $line->total_value);
        }
    }

    public function test_web_sales_reuses_upgrade_execution_and_shared_configured_cogs_without_staff_financial_projection(): void
    {
        [$owner, $warehouse, $product] = $this->baseFoundation();
        $staff = $this->user(EmployeeRole::Staff);
        $ram8 = $this->fixtureComponent($warehouse, ComponentType::Ram, '8GB DDR4 Web', 8, 'gb', 'DDR4', 2, '45.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Ram, 'RAM-1', false)]);
        [$configuration, $recipe] = $this->ramConfiguration($profile, $owner, 8192, $ram8, '8GB RAM Web', '10.0000');
        $order = app(WebSalesService::class)->completeSale(new WebSalesOrderData(
            customerName: 'Phase 1C Customer',
            customerPhone: '+971500000001',
            channel: WebSalesChannel::WalkIn,
            deliveryType: WebSalesDeliveryType::ShopPickup,
            courierName: null,
            trackingNumber: null,
            items: [new OrderItemData($product->id, 1, '1300.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id)],
            idempotencyKey: (string) Str::uuid(),
        ), $owner);
        $execution = OrderUpgradeExecution::query()->sole();

        $ownerRow = app(WebSalesReadService::class)->orders($owner)->whereKey($order)->firstOrFail();
        $this->assertEquals($execution->final_configured_cogs, $ownerRow->cogs_total);
        $this->assertEquals('245.0000', $ownerRow->gross_profit);

        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->employee->id,
            'team_id_at_assignment' => $staff->employee->team_id,
            'team_name_at_assignment' => $staff->employee->team?->name,
            'assigned_by_user_id' => $owner->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
        $staffSql = app(WebSalesReadService::class)->orders($staff)->toSql();
        $this->assertStringNotContainsString('final_configured_cogs', $staffSql);
        $this->assertStringNotContainsString('cogs_total', $staffSql);
        $this->assertStringNotContainsString('gross_profit', $staffSql);
    }

    public function test_two_orders_competing_for_the_same_ssd_stock_have_one_valid_winner(): void
    {
        [$owner, $warehouse, $product, $baseInventory] = $this->baseFoundation();
        $ssd512 = $this->fixtureComponent($warehouse, ComponentType::Ssd, '512GB Competition', 512, 'gb', 'NVMe', 1, '100.0000');
        $profile = $this->profile($product, $owner, [$this->slot(HardwareSubsystem::Storage, 'M2-1', false, null, 'NVMe')]);
        $configuration = SalesConfiguration::query()->create($this->configuration($profile, $owner, '512GB Competition', ['target_storage_total_gb' => 512]));
        $recipe = UpgradeRecipe::query()->create($this->recipe($configuration, $owner, 'Install 512GB', '0.0000'));
        $recipe->lines()->create($this->line(1, UpgradeRecipeOperation::Install, target: 'M2-1', install: $ssd512));

        app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
            new OrderItemData($product->id, 1, '1400.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
        ]), $owner);

        try {
            app(SaveAndReserveOrder::class)->handle($this->orderData($warehouse, $owner, [
                new OrderItemData($product->id, 1, '1400.00', salesConfigurationId: $configuration->id, upgradeRecipeId: $recipe->id),
            ]), $owner);
            $this->fail('A competing SSD reservation should fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('required: 1; available: 0', collect($exception->errors())->flatten()->join(' '));
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, $baseInventory->refresh()->reserved_quantity);
        $this->assertSame(1, $ssd512->inventories()->where('warehouse_id', $warehouse->id)->firstOrFail()->reserved_quantity);
    }

    private function baseFoundation(): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = Product::factory()->create(['status' => ProductStatus::Active, 'selling_price' => '1200.00']);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 10, 'reserved_quantity' => 0, 'average_cost' => '1000.0000',
        ]);

        return [$owner, $warehouse, $product, $inventory];
    }

    private function user(EmployeeRole $role): User
    {
        $email = $role === EmployeeRole::Owner ? 'phase1c-owner-'.Str::random(8).'@example.com' : 'phase1c-'.Str::random(8).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $email]);

        return $user->refresh();
    }

    private function fixtureComponent(Warehouse $warehouse, ComponentType $type, string $specification, int $capacity, string $unit, string $interface, int $available, string $cost, string $recovery = '0.0000', ?User $approver = null): Component
    {
        $component = Component::factory()->create([
            'component_type' => $type, 'specification' => $specification, 'capacity_value' => $capacity,
            'capacity_unit' => $unit, 'interface_type' => $interface, 'approved_oem_recovery_value' => $recovery,
            'recovery_approved_by_user_id' => bccomp($recovery, '0.0000', 4) > 0 ? $approver?->id : null,
            'recovery_approved_at' => bccomp($recovery, '0.0000', 4) > 0 ? now() : null,
            'recovery_reason' => bccomp($recovery, '0.0000', 4) > 0 ? 'Approved OEM recovery value' : null,
        ]);
        ProductInventory::factory()->create([
            'product_id' => $component->product_id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => $available, 'reserved_quantity' => 0, 'average_cost' => $cost,
        ]);

        return $component->load('product');
    }

    private function profile(Product $product, User $owner, array $slots): ProductHardwareProfile
    {
        $profile = ProductHardwareProfile::query()->create([
            'product_id' => $product->id, 'profile_version' => 1, 'ram_upgradeable' => true,
            'max_supported_ram_mb' => 32768, 'storage_upgradeable' => true,
            'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ]);
        foreach ($slots as $slot) {
            $profile->slots()->create($slot);
        }

        return $profile;
    }

    private function slot(HardwareSubsystem $subsystem, string $key, bool $occupied, ?Component $component = null, ?string $interface = 'DDR4'): array
    {
        return [
            'subsystem' => $subsystem, 'slot_key' => $key, 'interface_type' => $interface,
            'is_soldered' => false, 'is_occupied' => $occupied, 'base_component_id' => $component?->id,
            'base_capacity_value' => $component?->capacity_value, 'base_capacity_unit' => $component?->capacity_unit, 'position' => 1,
        ];
    }

    private function ramConfiguration(ProductHardwareProfile $profile, User $owner, int $targetRam, Component $install, string $name, string $labour = '0.0000'): array
    {
        $configuration = SalesConfiguration::query()->create($this->configuration($profile, $owner, $name, ['target_ram_mb' => $targetRam]));
        $recipe = UpgradeRecipe::query()->create($this->recipe($configuration, $owner, $name.' build', $labour));
        $ramOneOccupied = (bool) $profile->slots()->where('slot_key', 'RAM-1')->value('is_occupied');
        if ($ramOneOccupied) {
            $recipe->lines()->create($this->line(1, UpgradeRecipeOperation::Keep, source: 'RAM-1'));
        }
        $targetSlot = $profile->slots()->where('slot_key', 'RAM-2')->exists() ? 'RAM-2' : 'RAM-1';
        $recipe->lines()->create($this->line($ramOneOccupied ? 2 : 1, UpgradeRecipeOperation::Install, target: $targetSlot, install: $install));

        return [$configuration, $recipe];
    }

    private function storageReplacement(ProductHardwareProfile $profile, User $owner, Component $old, Component $install, UpgradeRecipeOperation $removal, string $name): array
    {
        $configuration = SalesConfiguration::query()->create($this->configuration($profile, $owner, $name, ['target_storage_total_gb' => 512]));
        $recipe = UpgradeRecipe::query()->create($this->recipe($configuration, $owner, $name.' build', '0.0000'));
        $recipe->lines()->create($this->line(1, $removal, source: 'M2-1', recovered: $removal === UpgradeRecipeOperation::RemoveAndDiscard ? null : $old));
        $recipe->lines()->create($this->line(2, UpgradeRecipeOperation::Install, target: 'M2-1', install: $install));

        return [$configuration, $recipe];
    }

    private function configuration(ProductHardwareProfile $profile, User $owner, string $name, array $targets): array
    {
        return array_merge([
            'product_id' => $profile->product_id, 'hardware_profile_version' => $profile->profile_version,
            'display_name' => $name, 'target_ram_mb' => null, 'target_storage_total_gb' => null,
            'target_storage_layout' => null, 'suggested_selling_addon' => '200.00', 'active' => true,
            'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ], $targets);
    }

    private function recipe(SalesConfiguration $configuration, User $owner, string $name, string $labour): array
    {
        return [
            'sales_configuration_id' => $configuration->id, 'hardware_profile_version' => $configuration->hardware_profile_version,
            'name' => $name, 'preferred' => true, 'priority' => 1, 'labour_unit_cost' => $labour,
            'active' => true, 'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ];
    }

    private function line(int $sequence, UpgradeRecipeOperation $operation, ?string $source = null, ?string $target = null, ?Component $install = null, ?Component $recovered = null, RecoveryValuationMethod $recovery = RecoveryValuationMethod::NotApplicable): array
    {
        return [
            'sequence' => $sequence, 'operation' => $operation, 'source_slot_key' => $source, 'target_slot_key' => $target,
            'install_component_id' => $install?->id, 'recovered_component_id' => $recovered?->id,
            'quantity_per_laptop' => 1, 'recovery_valuation_method' => $recovery,
        ];
    }

    private function orderData(Warehouse $warehouse, User $owner, array $items): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData(
            warehouseId: $warehouse->id, platformId: null, externalOrderNumber: null,
            orderDate: now()->toDateString(), handledByEmployeeId: $owner->employee->id,
            notes: null, items: $items, idempotencyKey: (string) Str::uuid(),
        );
    }
}
