<?php

namespace Tests\Feature\ServiceCases;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Pages\Inventory\DamagedItems;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\InternalRepairs\Pages\ListInternalRepairs;
use App\Filament\Resources\InternalRepairs\Pages\ViewInternalRepair;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\WarrantyRepairs\Pages\ListWarrantyRepairs;
use App\Models\CustomerReturn;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Order;
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

class DamagedItemSendToRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_action_creates_provenance_linked_internal_repair_without_inventory_change(): void
    {
        $foundation = $this->foundation('DMG-2026-991001');
        $before = $this->inventoryState($foundation['inventory']);
        $movementCount = $foundation['inventory']->movements()->count();
        $this->actingAs($foundation['owner']);

        $component = Livewire::test(DamagedItems::class)
            ->assertActionExists('sendToRepair', arguments: ['damageId' => $foundation['damage']->id])
            ->callAction('sendToRepair', [
                'issue_description' => 'Repair the damaged display assembly',
                'service_provider' => 'Internal Workshop',
                'notes' => 'Handle as company-owned inventory',
            ], ['damageId' => $foundation['damage']->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Repair case created');

        $case = WarrantyRepair::query()->sole();
        $component->assertRedirect(InternalRepairResource::getUrl('view', ['record' => $case]));
        $this->assertSame(WarrantyRepairSource::DamagedItem, $case->source);
        $this->assertSame($foundation['damage']->id, $case->damaged_stock_event_id);
        $this->assertSame($foundation['product']->id, $case->product_id);
        $this->assertSame($foundation['inventory']->id, $case->product_inventory_id);
        $this->assertSame($foundation['warehouse']->id, $case->warehouse_id);
        $this->assertSame($foundation['platform']->id, $case->marketplace_platform_id);
        $this->assertSame($foundation['order']->id, $case->order_id);
        $this->assertSame($foundation['return']->id, $case->customer_return_id);
        $this->assertSame($foundation['damage']->quantity, $case->quantity);
        $this->assertSame($before, $this->inventoryState($foundation['inventory']->refresh()));
        $this->assertSame($movementCount, $foundation['inventory']->movements()->count());
    }

    public function test_internal_and_external_repairs_are_separated_and_provenance_is_clickable(): void
    {
        $foundation = $this->foundation('DMG-2026-991007');
        $internal = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $external = WarrantyRepair::query()->create([
            'reference' => 'WR-2026-991008', 'product_id' => $foundation['product']->id, 'warehouse_id' => $foundation['warehouse']->id,
            'quantity' => 1, 'source' => WarrantyRepairSource::Manual, 'issue_description' => 'External customer service',
            'received_at' => now(), 'status' => WarrantyRepairStatus::Received, 'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $foundation['owner']->id,
        ]);
        $this->actingAs($foundation['owner']);

        Livewire::test(ListInternalRepairs::class)
            ->assertCanSeeTableRecords([$internal])
            ->assertCanNotSeeTableRecords([$external])
            ->assertSee('Customer Return')
            ->assertSee($foundation['damage']->reference);
        Livewire::test(ListWarrantyRepairs::class)
            ->assertCanSeeTableRecords([$external])
            ->assertCanNotSeeTableRecords([$internal]);

        Livewire::test(ViewInternalRepair::class, ['record' => $internal->getRouteKey()])
            ->assertSee($foundation['damage']->reference)
            ->assertSee($foundation['return']->reference)
            ->assertSee($foundation['order']->reference)
            ->assertSeeHtml(CustomerReturnResource::getUrl('view', ['record' => $foundation['return']]))
            ->assertSeeHtml(OrderResource::getUrl('view', ['record' => $foundation['order']]));
    }

    public function test_internal_received_case_offers_only_send_to_technician_as_next_action(): void
    {
        $foundation = $this->foundation('DMG-2026-991009');
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $this->actingAs($foundation['owner']);

        Livewire::test(ListInternalRepairs::class)
            ->assertTableActionVisible('next_send_to_technician', $case)
            ->assertTableActionDoesNotExist('next_under_inspection')
            ->assertTableActionDoesNotExist('next_dispatched_back')
            ->assertTableActionHidden('next_cannot_repair', $case);
    }

    public function test_genuine_warehouse_damage_uses_warehouse_damage_as_original_source(): void
    {
        $foundation = $this->foundation('DMG-2026-991010');
        $foundation['damage']->forceFill(['source' => DamagedStockSource::WarehouseDamage])->saveQuietly();
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage']->refresh(), ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $this->actingAs($foundation['owner']);

        Livewire::test(ListInternalRepairs::class)
            ->assertCanSeeTableRecords([$case])
            ->assertSee('Warehouse Damage');
    }

    public function test_duplicate_active_repair_for_same_damage_and_quantity_is_rejected(): void
    {
        $foundation = $this->foundation('DMG-2026-991002');
        $service = app(WarrantyRepairService::class);
        $service->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);

        try {
            $service->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);
            $this->fail('A duplicate active repair was created.');
        } catch (WarrantyRepairException $exception) {
            $this->assertSame('The remaining damaged quantity is already covered by an active repair.', $exception->getMessage());
        }

        $this->assertDatabaseCount('warranty_repairs', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_internal_repair_qc_pass_moves_damaged_to_available_once_and_preserves_total_owned(): void
    {
        $foundation = $this->foundation('DMG-2026-991003');
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $beforeTotalOwned = $foundation['inventory']->locationTotalOwned();
        $beforeAvailable = $foundation['inventory']->available_quantity;
        $beforeDamaged = $foundation['inventory']->damaged_quantity;
        $case = $this->advanceToQc($case, $foundation['owner']);

        $this->actingAs($foundation['owner']);
        Livewire::test(ViewInternalRepair::class, ['record' => $case->getRouteKey()])
            ->assertActionVisible('next_ready_to_return')
            ->assertActionVisible('next_cannot_repair')
            ->assertActionDoesNotExist('next_dispatched_back');

        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $foundation['owner']);

        $inventory = $foundation['inventory']->refresh();
        $this->assertSame(WarrantyRepairStatus::Completed, $case->status);
        $this->assertSame($beforeAvailable + 1, $inventory->available_quantity);
        $this->assertSame($beforeDamaged - 1, $inventory->damaged_quantity);
        $this->assertSame($beforeTotalOwned, $inventory->locationTotalOwned());
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'warranty_repair_restored', 'available_delta' => 1, 'damaged_delta' => -1]);

        $this->expectException(WarrantyRepairException::class);
        app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $foundation['owner']);
    }

    public function test_internal_repair_qc_fail_leaves_damaged_and_total_owned_unchanged(): void
    {
        $foundation = $this->foundation('DMG-2026-991004');
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $before = $this->inventoryState($foundation['inventory']);
        $beforeTotalOwned = $foundation['inventory']->locationTotalOwned();
        $case = $this->advanceToQc($case, $foundation['owner']);

        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::CannotRepair, $foundation['owner'], 'Repair failed final QC');

        $inventory = $foundation['inventory']->refresh();
        $this->assertSame(WarrantyRepairStatus::Completed, $case->status);
        $this->assertSame($before, $this->inventoryState($inventory));
        $this->assertSame($beforeTotalOwned, $inventory->locationTotalOwned());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_qc_pass_resolves_quantity_and_prevents_another_repair(): void
    {
        $foundation = $this->foundation('DMG-2026-991011');
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['quantity' => 1, 'idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $case = $this->advanceToQc($case, $foundation['owner']);
        app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $foundation['owner']);

        $this->assertSame(0, app(DamagedStockAvailabilityService::class)->remaining($foundation['damage']));
        $activeRows = collect(app(DamagedItemsQueueService::class)->paginate($foundation['owner'], ['status' => 'damaged'])->items());
        $this->assertNull($activeRows->firstWhere('reference', $foundation['damage']->reference));

        try {
            app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['quantity' => 1, 'idempotency_key' => (string) Str::uuid()], $foundation['owner']);
            $this->fail('Resolved damage was sent to repair again.');
        } catch (WarrantyRepairException $exception) {
            $this->assertSame('This damaged quantity has already been resolved and is no longer available for repair.', $exception->getMessage());
        }

        $this->assertDatabaseCount('warranty_repairs', 1);
        $this->assertSame(1, $foundation['damage']->refresh()->quantity);
    }

    public function test_qc_fail_keeps_quantity_available_for_repeat_repair(): void
    {
        $foundation = $this->foundation('DMG-2026-991012');
        $first = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['quantity' => 1, 'idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $first = $this->advanceToQc($first, $foundation['owner']);
        app(WarrantyRepairService::class)->transition($first, WarrantyRepairStatus::CannotRepair, $foundation['owner'], 'First repair failed QC');

        $this->assertSame(1, app(DamagedStockAvailabilityService::class)->remaining($foundation['damage']));
        $second = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['quantity' => 1, 'idempotency_key' => (string) Str::uuid()], $foundation['owner']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('warranty_repairs', 2);
        $this->assertSame(2, $foundation['inventory']->refresh()->damaged_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_partial_qc_pass_leaves_only_remaining_quantity_actionable(): void
    {
        $foundation = $this->foundation('DMG-2026-991013');
        $foundation['damage']->forceFill(['quantity' => 3])->saveQuietly();
        $foundation['inventory']->forceFill(['damaged_quantity' => 3])->save();
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage']->refresh(), ['quantity' => 1, 'idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $case = $this->advanceToQc($case, $foundation['owner']);
        app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $foundation['owner']);

        $availability = app(DamagedStockAvailabilityService::class);
        $this->assertSame(2, $availability->remaining($foundation['damage']));
        $row = app(DamagedItemsQueueService::class)->paginate($foundation['owner'], ['status' => 'damaged'])->items()[0];
        $this->assertSame(2, $row['quantity']);
        $this->assertSame(2, $row['available_repair_quantity']);

        try {
            app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['quantity' => 3, 'idempotency_key' => (string) Str::uuid()], $foundation['owner']);
            $this->fail('Repair quantity exceeded remaining damage.');
        } catch (WarrantyRepairException $exception) {
            $this->assertSame('Only 2 damaged units are available for repair.', $exception->getMessage());
        }

        $next = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['quantity' => 2, 'idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $this->assertSame(2, $next->quantity);
        $this->assertSame(0, $availability->availableForRepair($foundation['damage']));
    }

    public function test_internal_company_owned_damage_provenance_never_exposes_dispatch_back(): void
    {
        $foundation = $this->foundation('DMG-2026-991006');
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $foundation['owner']);
        $case->forceFill([
            'source' => WarrantyRepairSource::Manual,
            'status' => WarrantyRepairStatus::ReadyToReturn,
        ])->save();
        $this->actingAs($foundation['owner']);

        Livewire::test(ViewInternalRepair::class, ['record' => $case->getRouteKey()])
            ->assertActionDoesNotExist('next_dispatched_back')
            ->assertActionVisible('next_completed');
    }

    public function test_send_to_repair_requires_permission_and_matching_responsibility_server_side(): void
    {
        $foundation = $this->foundation('DMG-2026-991005');
        $staff = User::factory()->create();
        $employee = Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        foreach (['damaged_stock.view', 'warranty_repair.create', 'warranty_repair.view', 'warranty_repair.update_status'] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $employee->id,
                'permission_key' => $permission,
                'effect' => EmployeePermissionEffect::Allow,
                'granted_by_user_id' => $foundation['owner']->id,
                'reason' => 'Repair workflow test',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($employee->id);

        try {
            app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $staff->refresh());
            $this->fail('Responsibility-free staff access was accepted.');
        } catch (AuthorizationException) {
        }

        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $employee->id, 'assigned_by_user_id' => $foundation['owner']->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $foundation['product']->id]);
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($foundation['damage'], ['idempotency_key' => (string) Str::uuid()], $staff->refresh());

        $this->assertSame($staff->id, $case->assigned_to_user_id);
        $this->assertDatabaseCount('warranty_repairs', 1);
    }

    private function advanceToQc(WarrantyRepair $case, User $owner): WarrantyRepair
    {
        foreach ([WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::ReceivedBack, WarrantyRepairStatus::QcPending] as $status) {
            $case = app(WarrantyRepairService::class)->transition($case, $status, $owner);
        }

        return $case;
    }

    /** @return array<string, mixed> */
    private function foundation(string $damageReference): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $platform = MarketplacePlatform::factory()->create();
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 6,
            'reserved_quantity' => 2,
            'damaged_quantity' => 2,
            'qc_pending_quantity' => 1,
            'qc_pending_value' => '100.0000',
            'marketplace_non_sellable_quantity' => 1,
            'marketplace_non_sellable_value' => '100.0000',
            'average_cost' => '100.0000',
        ]);
        $order = Order::query()->create([
            'reference' => 'SO-'.Str::upper(Str::random(12)), 'source' => 'marketplace', 'status' => 'fulfilled',
            'warehouse_id' => $warehouse->id, 'marketplace_platform_id' => $platform->id,
            'order_date' => now()->toDateString(), 'subtotal' => 0, 'discount_total' => 0, 'vat_total' => 0,
            'grand_total' => 0, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        $return = CustomerReturn::query()->create([
            'reference' => 'RTN-'.Str::upper(Str::random(12)), 'order_id' => $order->id,
            'marketplace_platform_id' => $platform->id, 'fulfillment_warehouse_id' => $warehouse->id,
            'receiving_warehouse_id' => $warehouse->id, 'status' => 'completed', 'return_source' => 'manual',
            'reported_at' => now(), 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $damage = DamagedStockEvent::query()->create([
            'reference' => $damageReference, 'product_inventory_id' => $inventory->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 1, 'source' => DamagedStockSource::CustomerReturn,
            'marketplace_platform_id' => $platform->id, 'customer_return_id' => $return->id, 'order_id' => $order->id,
            'reason' => 'Display damaged by customer', 'occurred_at' => now(), 'reported_by_user_id' => $owner->id,
            'status' => DamagedStockStatus::Damaged, 'idempotency_key' => (string) Str::uuid(),
        ]);

        return compact('owner', 'product', 'warehouse', 'platform', 'inventory', 'order', 'return', 'damage');
    }

    /** @return array<string, mixed> */
    private function inventoryState(ProductInventory $inventory): array
    {
        return $inventory->only([
            'available_quantity', 'reserved_quantity', 'damaged_quantity', 'qc_pending_quantity', 'qc_pending_value',
            'marketplace_non_sellable_quantity', 'marketplace_non_sellable_value', 'average_cost',
        ]);
    }
}
