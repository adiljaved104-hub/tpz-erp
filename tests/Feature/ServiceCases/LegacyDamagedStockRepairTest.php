<?php

namespace Tests\Feature\ServiceCases;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Pages\Inventory\DamagedItems;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\InternalRepairs\Pages\ViewInternalRepair;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Services\Inventory\DamagedItemsQueueService;
use App\Services\Inventory\DamagedStockAvailabilityService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LegacyDamagedStockRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_damaged_stock_exposes_action_and_creates_partial_internal_repair_without_inventory_change(): void
    {
        $foundation = $this->foundation(2);
        $before = $this->inventoryState($foundation['inventory']);
        $this->actingAs($foundation['owner']);

        $component = Livewire::test(DamagedItems::class)
            ->assertSee('Old Damaged Stock')
            ->assertActionExists('startLegacyRepair', arguments: ['inventoryId' => $foundation['inventory']->id])
            ->callAction('startLegacyRepair', [
                'quantity' => 1,
                'issue_description' => 'Repair legacy damaged laptop',
                'service_provider' => 'Legacy Repair Workshop',
            ], ['inventoryId' => $foundation['inventory']->id])
            ->assertHasNoActionErrors();

        $case = WarrantyRepair::query()->sole();
        $component->assertRedirect(InternalRepairResource::getUrl('view', ['record' => $case]));
        $this->assertSame(WarrantyRepairSource::DamagedItem, $case->source);
        $this->assertTrue($case->isLegacyDamagedRepair());
        $this->assertSame(1, $case->quantity);
        $this->assertSame($foundation['inventory']->id, $case->product_inventory_id);
        $this->assertNull($case->damaged_stock_event_id);
        $this->assertNull($case->order_id);
        $this->assertNull($case->customer_return_id);
        $this->assertNull($case->marketplace_platform_id);
        $this->assertSame($before, $this->inventoryState($foundation['inventory']->refresh()));
        $this->assertDatabaseCount('damaged_stock_events', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(1, app(DamagedStockAvailabilityService::class)->legacyAvailableForRepair($foundation['inventory']));
    }

    public function test_active_legacy_repairs_cannot_over_allocate_damaged_balance(): void
    {
        $foundation = $this->foundation(2);
        $service = app(WarrantyRepairService::class);
        $first = $service->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(1), $foundation['owner']);

        try {
            $service->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(2), $foundation['owner']);
            $this->fail('Legacy damaged inventory was over-allocated.');
        } catch (WarrantyRepairException $exception) {
            $this->assertSame('Only 1 Old Damaged Stock units are available for repair.', $exception->getMessage());
        }

        $second = $service->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(1), $foundation['owner']);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(0, app(DamagedStockAvailabilityService::class)->legacyAvailableForRepair($foundation['inventory']));
        $this->assertCount(0, app(DamagedItemsQueueService::class)->paginate($foundation['owner'], ['status' => 'damaged'])->items());
        $this->assertSame(2, $foundation['inventory']->refresh()->damaged_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_legacy_qc_pass_restores_available_once_and_removes_fully_consumed_queue_row(): void
    {
        $foundation = $this->foundation(1);
        $beforeTotal = $foundation['inventory']->locationTotalOwned();
        $case = app(WarrantyRepairService::class)->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(1), $foundation['owner']);
        $case = $this->advanceToQc($case, $foundation['owner']);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $foundation['owner']);

        $inventory = $foundation['inventory']->refresh();
        $this->assertSame(WarrantyRepairStatus::Completed, $case->status);
        $this->assertSame(6, $inventory->available_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertSame($beforeTotal, $inventory->locationTotalOwned());
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertCount(0, app(DamagedItemsQueueService::class)->paginate($foundation['owner'], ['status' => 'damaged'])->items());

        try {
            app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $foundation['owner']);
            $this->fail('Legacy repair QC Pass posted twice.');
        } catch (WarrantyRepairException) {
        }
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_legacy_qc_fail_leaves_stock_and_allows_a_later_repair(): void
    {
        $foundation = $this->foundation(1);
        $before = $this->inventoryState($foundation['inventory']);
        $first = app(WarrantyRepairService::class)->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(1), $foundation['owner']);
        $first = $this->advanceToQc($first, $foundation['owner']);
        app(WarrantyRepairService::class)->transition($first, WarrantyRepairStatus::CannotRepair, $foundation['owner'], 'Repair failed QC');

        $this->assertSame($before, $this->inventoryState($foundation['inventory']->refresh()));
        $this->assertSame(1, app(DamagedStockAvailabilityService::class)->legacyAvailableForRepair($foundation['inventory']));
        $second = app(WarrantyRepairService::class)->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(1), $foundation['owner']);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->actingAs($foundation['owner']);
        Livewire::test(ViewInternalRepair::class, ['record' => $second->getRouteKey()])
            ->assertSee('Old Damaged Stock')
            ->assertSee('Historical damage event was not recorded.')
            ->assertDontSee('Warehouse Damage');
    }

    public function test_legacy_repair_reuses_permission_and_product_responsibility_scope(): void
    {
        $foundation = $this->foundation(1);
        $staffUser = User::factory()->create();
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        foreach (['damaged_stock.view', 'warranty_repair.create', 'warranty_repair.view', 'warranty_repair.update_status'] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $staff->id,
                'permission_key' => $permission,
                'effect' => EmployeePermissionEffect::Allow,
                'granted_by_user_id' => $foundation['owner']->id,
                'reason' => 'Legacy repair responsibility test',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->id);

        try {
            app(WarrantyRepairService::class)->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(1), $staffUser->refresh());
            $this->fail('Staff without matching responsibility created a legacy repair.');
        } catch (AuthorizationException) {
        }

        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->id,
            'assigned_by_user_id' => $foundation['owner']->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create([
            'assignment_id' => $assignment->id,
            'product_id' => $foundation['product']->id,
        ]);

        $case = app(WarrantyRepairService::class)->createFromLegacyDamagedInventory($foundation['inventory'], $this->data(1), $staffUser->refresh());
        $this->assertSame($staffUser->id, $case->assigned_to_user_id);
    }

    /** @return array<string, mixed> */
    private function foundation(int $damagedQuantity): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 5,
            'reserved_quantity' => 1,
            'damaged_quantity' => $damagedQuantity,
            'average_cost' => '100.0000',
        ]);

        return compact('owner', 'product', 'warehouse', 'inventory');
    }

    /** @return array<string, mixed> */
    private function data(int $quantity): array
    {
        return [
            'quantity' => $quantity,
            'issue_description' => 'Repair Old Damaged Stock',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    private function advanceToQc(WarrantyRepair $case, User $owner): WarrantyRepair
    {
        foreach ([WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::ReceivedBack, WarrantyRepairStatus::QcPending] as $status) {
            $case = app(WarrantyRepairService::class)->transition($case, $status, $owner);
        }

        return $case;
    }

    /** @return array<string, mixed> */
    private function inventoryState(ProductInventory $inventory): array
    {
        return $inventory->only([
            'available_quantity', 'reserved_quantity', 'damaged_quantity', 'qc_pending_quantity',
            'marketplace_non_sellable_quantity', 'average_cost',
        ]);
    }
}
