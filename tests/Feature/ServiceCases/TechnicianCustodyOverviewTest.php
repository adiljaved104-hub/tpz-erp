<?php

namespace Tests\Feature\ServiceCases;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Filament\Pages\Service\TechnicianCustody;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Models\WarrantyRepairStatusEvent;
use App\Services\ServiceCases\TechnicianCustodyService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TechnicianCustodyOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_derives_current_custody_units_summaries_and_filters(): void
    {
        [$owner, $product, $warehouse, $platform] = $this->foundation();
        $assignee = User::factory()->create();
        Employee::factory()->for($assignee)->role(EmployeeRole::Staff)->create(['email' => $assignee->email]);
        $sent = $this->case($owner, $product, $warehouse, WarrantyRepairStatus::SendToTechnician, 2, 'Tech A', now()->subDays(20), $platform, $assignee);
        $inRepair = $this->case($owner, $product, $warehouse, WarrantyRepairStatus::InRepair, 1, 'Tech A', now()->subDays(2), $platform, $assignee);
        $waiting = $this->case($owner, $product, $warehouse, WarrantyRepairStatus::WaitingForParts, 3, 'Tech B', now()->subDays(3), $platform);
        $completed = $this->case($owner, $product, $warehouse, WarrantyRepairStatus::RepairCompleted, 1, 'Tech B', now()->subDays(4), $platform);
        $this->case($owner, $product, $warehouse, WarrantyRepairStatus::ReceivedBack, 9, 'Tech A', now()->subDays(5), $platform);
        $service = app(TechnicianCustodyService::class);

        $summary = $service->summary($owner, []);
        $this->assertSame(['total' => 7, 'in_repair' => 1, 'waiting_for_parts' => 3, 'overdue' => 2, 'repair_completed' => 1], $summary);
        $this->assertEqualsCanonicalizing([$sent->id, $inRepair->id, $waiting->id, $completed->id], collect($service->paginate($owner, [])->pluck('id')->all())->all());
        $this->assertEqualsCanonicalizing([$sent->id, $inRepair->id], $service->paginate($owner, ['technician' => 'Tech A'])->pluck('id')->all());
        $this->assertSame([$waiting->id], $service->paginate($owner, ['status' => WarrantyRepairStatus::WaitingForParts->value])->pluck('id')->all());
        $this->assertCount(4, $service->paginate($owner, ['platform_id' => $platform->id, 'product_id' => $product->id])->items());
        $this->assertCount(2, $service->paginate($owner, ['assigned_to_user_id' => $assignee->id])->items());

        $this->actingAs($owner);
        Livewire::test(TechnicianCustody::class)
            ->assertSee('Total Units With Technicians')
            ->assertSee($sent->reference)
            ->set('status', WarrantyRepairStatus::WaitingForParts->value)
            ->assertSee($waiting->reference)
            ->assertDontSee($sent->reference);
    }

    public function test_counts_update_as_case_enters_and_leaves_technician_custody_without_inventory_movement(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5, 'reserved_quantity' => 2, 'damaged_quantity' => 0, 'average_cost' => '100.0000']);
        $before = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $case = app(WarrantyRepairService::class)->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1,
            'source' => WarrantyRepairSource::Manual->value, 'issue_description' => 'Customer-owned repair',
            'received_at' => now(), 'service_provider' => 'Tech C', 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $service = app(TechnicianCustodyService::class);
        $this->assertSame(0, $service->summary($owner, [])['total']);

        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::UnderInspection, $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::SendToTechnician, $owner);
        $this->assertSame(1, $service->summary($owner, [])['total']);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::InRepair, $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::RepairCompleted, $owner);
        $this->assertSame(1, $service->summary($owner, [])['repair_completed']);
        app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReceivedBack, $owner);

        $this->assertSame(0, $service->summary($owner, [])['total']);
        $this->assertSame($before, $inventory->refresh()->only(array_keys($before)));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_internal_damaged_unit_remains_in_damaged_inventory_while_with_technician(): void
    {
        [$owner, $product, $warehouse, $platform] = $this->foundation();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4, 'reserved_quantity' => 1, 'damaged_quantity' => 2, 'average_cost' => '125.0000']);
        $damage = DamagedStockEvent::query()->create([
            'reference' => 'DMG-2026-992001', 'product_inventory_id' => $inventory->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 1, 'source' => DamagedStockSource::WarehouseDamage,
            'marketplace_platform_id' => $platform->id, 'reason' => 'Internal damaged repair', 'occurred_at' => now(),
            'reported_by_user_id' => $owner->id, 'status' => DamagedStockStatus::Damaged, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $before = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($damage, ['service_provider' => 'Internal Technician', 'idempotency_key' => (string) Str::uuid()], $owner);
        app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::SendToTechnician, $owner);

        $this->assertSame(1, app(TechnicianCustodyService::class)->summary($owner, [])['total']);
        $this->assertSame($before, $inventory->refresh()->only(array_keys($before)));
        $this->assertDatabaseCount('stock_movements', 0);
        Livewire::actingAs($owner)->test(TechnicianCustody::class)
            ->assertSee('Internal Repair')
            ->assertSee($case->reference);
    }

    public function test_staff_overview_is_permission_and_responsibility_scoped(): void
    {
        [$owner, $assignedProduct, $warehouse, $platform] = $this->foundation();
        $otherProduct = Product::factory()->create();
        $assignedCase = $this->case($owner, $assignedProduct, $warehouse, WarrantyRepairStatus::InRepair, 1, 'Scoped Technician', now()->subDay(), $platform);
        $otherCase = $this->case($owner, $otherProduct, $warehouse, WarrantyRepairStatus::InRepair, 1, 'Other Technician', now()->subDay(), $platform);
        $staff = User::factory()->create();
        $employee = Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $employee->id, 'permission_key' => 'warranty_repair.view',
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
            'reason' => 'Technician custody responsibility test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($employee->id);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $employee->id, 'assigned_by_user_id' => $owner->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $assignedProduct->id]);

        $rows = app(TechnicianCustodyService::class)->paginate($staff->refresh(), [])->pluck('id')->all();
        $this->assertSame([$assignedCase->id], $rows);
        $this->assertNotContains($otherCase->id, $rows);
        $this->actingAs($staff);
        Livewire::test(TechnicianCustody::class)->assertSee($assignedCase->reference)->assertDontSee($otherCase->reference);
    }

    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $platform = MarketplacePlatform::factory()->create();

        return [$owner, $product, $warehouse, $platform];
    }

    private function case(User $owner, Product $product, Warehouse $warehouse, WarrantyRepairStatus $status, int $quantity, string $provider, \DateTimeInterface $receivedAt, MarketplacePlatform $platform, ?User $assignee = null): WarrantyRepair
    {
        $case = WarrantyRepair::query()->create([
            'reference' => 'WR-'.Str::upper(Str::random(14)), 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'marketplace_platform_id' => $platform->id, 'quantity' => $quantity, 'source' => WarrantyRepairSource::Manual,
            'issue_description' => 'Technician custody test', 'service_provider' => $provider, 'received_at' => $receivedAt,
            'expected_return_at' => now()->addDays(2), 'assigned_to_user_id' => $assignee?->id, 'status' => $status,
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        WarrantyRepairStatusEvent::query()->create([
            'warranty_repair_id' => $case->id, 'from_status' => WarrantyRepairStatus::UnderInspection,
            'to_status' => WarrantyRepairStatus::SendToTechnician, 'changed_by_user_id' => $owner->id,
            'changed_at' => now()->subDays(3),
        ]);

        return $case;
    }
}
