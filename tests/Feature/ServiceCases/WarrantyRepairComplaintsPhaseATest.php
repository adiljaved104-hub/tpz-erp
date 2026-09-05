<?php

namespace Tests\Feature\ServiceCases;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintResolution;
use App\Enums\ComplaintStatus;
use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\ComplaintException;
use App\Exceptions\WarrantyRepairException;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\ReferenceSequenceService;
use App\Services\ServiceCases\ComplaintService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarrantyRepairComplaintsPhaseATest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_warranty_lifecycle_is_inventory_neutral_and_reference_is_reserved_before_transaction(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation();
        $before = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $movementCount = DB::table('stock_movements')->count();
        $ambient = DB::transactionLevel();
        $probe = new class extends ReferenceSequenceService
        {
            public int $level = -1;

            public function nextWarrantyRepairReference(?int $year = null): string
            {
                $this->level = DB::transactionLevel();

                return 'WR-2026-990001';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $case = app(WarrantyRepairService::class)->create($this->warrantyData($product->id, $warehouse->id), $owner);
        $this->assertSame($ambient, $probe->level);
        $this->assertSame(WarrantyRepairStatus::Received, $case->status);
        foreach ([WarrantyRepairStatus::UnderInspection, WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair,
            WarrantyRepairStatus::WaitingForParts, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::ReceivedBack,
            WarrantyRepairStatus::QcPending, WarrantyRepairStatus::ReadyToReturn, WarrantyRepairStatus::DispatchedBack,
            WarrantyRepairStatus::Completed] as $status) {
            $case = app(WarrantyRepairService::class)->transition($case, $status, $owner);
        }
        $this->assertSame(WarrantyRepairStatus::Completed, $case->status);
        $this->assertSame($before, $inventory->refresh()->only(array_keys($before)));
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertDatabaseCount('warranty_repair_status_events', 11);
    }

    public function test_warranty_can_be_created_from_damaged_item_and_is_idempotent(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation();
        $damage = DamagedStockEvent::query()->create(['reference' => 'DMG-2026-990001', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'product_inventory_id' => $inventory->id, 'quantity' => 1, 'source' => 'internal_warehouse', 'reason' => 'Internal damage', 'occurred_at' => now(),
            'reported_by_user_id' => $owner->id, 'status' => 'damaged', 'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now()]);
        $key = (string) Str::uuid();
        $data = ['issue_description' => 'Internal damage', 'idempotency_key' => $key];
        $first = app(WarrantyRepairService::class)->createFromDamagedItem($damage, $data, $owner);
        $second = app(WarrantyRepairService::class)->createFromDamagedItem($damage, $data, $owner);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(WarrantyRepairSource::DamagedItem, $first->source);
    }

    public function test_complaint_categories_resolve_without_warranty_and_convert_once_when_repair_is_needed(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation();
        foreach ([ComplaintCategory::ChargerMissing, ComplaintCategory::KeyboardMissing, ComplaintCategory::MouseMissing, ComplaintCategory::PenStylusMissing] as $category) {
            $complaint = app(ComplaintService::class)->create(['category' => $category->value, 'description' => $category->getLabel(), 'product_id' => $product->id, 'quantity' => 1, 'idempotency_key' => (string) Str::uuid()], $owner);
            $complaint = app(ComplaintService::class)->transition($complaint, ComplaintStatus::Resolved, $owner, ComplaintResolution::AccessorySent, 'Accessory dispatched');
            $this->assertSame(ComplaintStatus::Resolved, $complaint->status);
            $this->assertNull($complaint->warranty_repair_id);
        }
        $repair = app(ComplaintService::class)->create(['category' => ComplaintCategory::ProductNotWorking->value, 'description' => 'Will not boot', 'product_id' => $product->id, 'quantity' => 1, 'idempotency_key' => (string) Str::uuid()], $owner);
        $data = ['warehouse_id' => $warehouse->id, 'idempotency_key' => (string) Str::uuid()];
        $warranty = app(ComplaintService::class)->convertToWarranty($repair, $data, $owner);
        $again = app(ComplaintService::class)->convertToWarranty($repair->refresh(), $data, $owner);
        $this->assertSame($warranty->id, $again->id);
        $this->assertSame($warranty->id, $repair->refresh()->warranty_repair_id);
        $this->assertDatabaseCount('warranty_repairs', 1);
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(5, $inventory->refresh()->available_quantity);
    }

    public function test_cancelled_complaint_requires_reason(): void
    {
        [$owner, $product] = $this->foundation();
        $complaint = app(ComplaintService::class)->create(['category' => ComplaintCategory::Other->value, 'description' => 'General issue', 'product_id' => $product->id, 'idempotency_key' => (string) Str::uuid()], $owner);
        $this->expectException(ComplaintException::class);
        app(ComplaintService::class)->transition($complaint, ComplaintStatus::Cancelled, $owner);
    }

    public function test_customer_cannot_repair_is_inventory_neutral_until_explicit_move_to_damaged_once(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation();
        $case = app(WarrantyRepairService::class)->create($this->warrantyData($product->id, $warehouse->id), $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::UnderInspection, $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::CannotRepair, $owner, 'Repair is not viable');
        $this->assertSame(1, $inventory->refresh()->damaged_quantity);
        $this->assertDatabaseCount('damaged_stock_events', 0);
        $key = (string) Str::uuid();
        $case = app(WarrantyRepairService::class)->moveToDamaged($case, 1, $warehouse->id, 'Customer refunded and unit retained', null, $key, $owner);
        $this->assertSame(2, $inventory->refresh()->damaged_quantity);
        $this->assertNotNull($case->moved_to_damaged_at);
        $this->assertDatabaseHas('damaged_stock_events', ['source' => 'warranty_service', 'source_type' => 'warranty_repair', 'source_id' => $case->id]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'warranty_retained_damaged', 'source_type' => 'warranty_repair', 'source_id' => $case->id, 'damaged_delta' => 1]);
        $this->assertDatabaseHas('stock_movements', ['source_id' => $case->id, 'unit_cost' => null, 'average_cost_before' => '100', 'average_cost_after' => '100']);
        $this->assertSame('100.0000', $inventory->refresh()->average_cost);
        try {
            app(WarrantyRepairService::class)->moveToDamaged($case->refresh(), 1, $warehouse->id, 'Duplicate', null, $key, $owner);
            $this->fail();
        } catch (WarrantyRepairException) {
        }
        $this->assertSame(2, $inventory->refresh()->damaged_quantity);
        $this->assertDatabaseCount('damaged_stock_events', 1);
    }

    public function test_move_to_damaged_reserves_references_before_transaction_and_rolls_back_on_failure(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation();
        $case = app(WarrantyRepairService::class)->create($this->warrantyData($product->id, $warehouse->id), $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::UnderInspection, $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::CannotRepair, $owner, 'Repair is not viable');
        $ambient = DB::transactionLevel();
        $probe = new class extends ReferenceSequenceService
        {
            /** @var list<int> */
            public array $levels = [];

            public function nextStockMovementReference(): string
            {
                $this->levels[] = DB::transactionLevel();

                return 'SM-2026-990021';
            }

            public function nextDamagedStockReference(?int $year = null): string
            {
                $this->levels[] = DB::transactionLevel();

                return 'DMG-2026-990021';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $logger = $this->mock(ActivityLogger::class);
        $logger->shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated persistence failure'));

        try {
            app(WarrantyRepairService::class)->moveToDamaged($case, 1, $warehouse->id, 'Retained after refund', null, (string) Str::uuid(), $owner);
            $this->fail('The simulated persistence failure was expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated persistence failure', $exception->getMessage());
        }

        $this->assertSame([$ambient, $ambient], $probe->levels);
        $this->assertSame(1, $inventory->refresh()->damaged_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('damaged_stock_events', 0);
        $this->assertNull($case->refresh()->moved_to_damaged_at);
    }

    public function test_staff_assignment_alone_does_not_authorize_move_to_damaged(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $case = app(WarrantyRepairService::class)->create($this->warrantyData($product->id, $warehouse->id), $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::UnderInspection, $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::CannotRepair, $owner, 'Repair is not viable');
        $staffUser = User::factory()->create();
        Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        $case->forceFill(['assigned_to_user_id' => $staffUser->id])->save();

        $this->expectException(AuthorizationException::class);
        app(WarrantyRepairService::class)->moveToDamaged($case, 1, $warehouse->id, 'Not authorized', null, (string) Str::uuid(), $staffUser);
    }

    public function test_internal_damaged_repair_qc_pass_moves_damaged_to_available_and_qc_fail_stays_damaged(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation();
        $damage = DamagedStockEvent::query()->create(['reference' => 'DMG-2026-990011', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'product_inventory_id' => $inventory->id, 'quantity' => 1, 'source' => 'internal_warehouse', 'reason' => 'Repair candidate', 'occurred_at' => now(), 'reported_by_user_id' => $owner->id, 'status' => 'damaged', 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $case = app(WarrantyRepairService::class)->createFromDamagedItem($damage, ['issue_description' => 'Repair', 'idempotency_key' => (string) Str::uuid()], $owner);
        foreach ([WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::ReceivedBack, WarrantyRepairStatus::QcPending, WarrantyRepairStatus::ReadyToReturn] as $status) {
            $case = app(WarrantyRepairService::class)->transition($case, $status, $owner);
        }
        $this->assertSame(6, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'warranty_repair_restored', 'available_delta' => 1, 'damaged_delta' => -1]);

        $inventory->forceFill(['available_quantity' => 5, 'damaged_quantity' => 1])->save();
        $damage2 = DamagedStockEvent::query()->create(['reference' => 'DMG-2026-990012', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'product_inventory_id' => $inventory->id, 'quantity' => 1, 'source' => 'internal_warehouse', 'reason' => 'Repair candidate', 'occurred_at' => now(), 'reported_by_user_id' => $owner->id, 'status' => 'damaged', 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $failed = app(WarrantyRepairService::class)->createFromDamagedItem($damage2, ['issue_description' => 'Repair', 'idempotency_key' => (string) Str::uuid()], $owner);
        foreach ([WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::ReceivedBack, WarrantyRepairStatus::QcPending, WarrantyRepairStatus::CannotRepair] as $status) {
            $failed = app(WarrantyRepairService::class)->transition($failed, $status, $owner, $status === WarrantyRepairStatus::CannotRepair ? 'Repair is not viable' : null);
        }
        $this->assertSame(5, $inventory->refresh()->available_quantity);
        $this->assertSame(1, $inventory->damaged_quantity);
    }

    public function test_dispatch_date_must_be_current_or_past_and_follow_receipt_and_ready_lifecycle(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $data = $this->warrantyData($product->id, $warehouse->id);
        $data['received_at'] = now()->subDays(5);
        $case = app(WarrantyRepairService::class)->create($data, $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::UnderInspection, $owner);
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $owner);
        $statusEventCount = $case->statusEvents()->count();

        foreach ([
            [now()->addMinute(), 'Dispatch date cannot be in the future.'],
            [now()->subDays(6), 'Dispatch date cannot be earlier than Received At.'],
            [now()->subDay(), 'Dispatch date cannot be earlier than the case becoming Ready to Return.'],
        ] as [$invalidDate, $message]) {
            try {
                app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::DispatchedBack, $owner, fields: ['dispatched_back_at' => $invalidDate]);
                $this->fail('The invalid dispatch date was accepted.');
            } catch (WarrantyRepairException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }

            $this->assertSame(WarrantyRepairStatus::ReadyToReturn, $case->refresh()->status);
            $this->assertNull($case->dispatched_back_at);
            $this->assertSame($statusEventCount, $case->statusEvents()->count());
        }

        $readyAt = now()->subDays(2);
        $case->forceFill(['qc_at' => $readyAt])->save();
        $case->statusEvents()->where('to_status', WarrantyRepairStatus::ReadyToReturn->value)->update(['changed_at' => $readyAt]);
        $validDispatchDate = now()->subDay()->startOfSecond();
        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::DispatchedBack, $owner, fields: ['dispatched_back_at' => $validDispatchDate]);

        $this->assertTrue($case->dispatched_back_at->equalTo($validDispatchDate));
    }

    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5, 'damaged_quantity' => 1, 'average_cost' => '100.0000']);

        return [$owner, $product, $warehouse, $inventory];
    }

    private function warrantyData(int $productId, int $warehouseId): array
    {
        return ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => 1, 'source' => WarrantyRepairSource::Manual->value,
            'issue_description' => 'Service inspection requested', 'received_at' => now(), 'idempotency_key' => (string) Str::uuid()];
    }
}
