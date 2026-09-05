<?php

namespace Tests\Feature\ServiceCases;

use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Resources\WarrantyRepairs\Pages\ListWarrantyRepairs;
use App\Filament\Resources\WarrantyRepairs\Pages\ViewWarrantyRepair;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Services\ServiceCases\WarrantyRepairLifecycleService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WarrantyRepairGuidedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_exposes_only_guided_valid_next_actions(): void
    {
        [$owner, $case] = $this->foundation();
        $lifecycle = app(WarrantyRepairLifecycleService::class);

        $this->assertSame([WarrantyRepairStatus::UnderInspection], $lifecycle->validTransitions($case));

        $case->status = WarrantyRepairStatus::UnderInspection;
        $this->assertSame(
            [WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::ReadyToReturn, WarrantyRepairStatus::CannotRepair],
            $lifecycle->validTransitions($case),
        );

        $case->status = WarrantyRepairStatus::InRepair;
        $this->assertSame(
            [WarrantyRepairStatus::WaitingForParts, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::CannotRepair],
            $lifecycle->validTransitions($case),
        );

        $case->status = WarrantyRepairStatus::WaitingForParts;
        $this->assertSame(
            [WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::CannotRepair],
            $lifecycle->validTransitions($case),
        );
    }

    public function test_received_case_shows_inspect_only_and_generic_update_is_removed(): void
    {
        [$owner, $case] = $this->foundation();
        $this->actingAs($owner);

        Livewire::test(ListWarrantyRepairs::class)
            ->assertTableActionVisible('next_under_inspection', $case)
            ->assertTableActionHidden('next_send_to_technician', $case)
            ->assertTableActionHidden('next_cannot_repair', $case)
            ->assertTableActionDoesNotExist('changeStatus');

        Livewire::test(ViewWarrantyRepair::class, ['record' => $case->getRouteKey()])
            ->assertActionVisible('next_under_inspection')
            ->assertActionHidden('next_send_to_technician')
            ->assertActionHidden('next_cannot_repair');
    }

    public function test_cannot_repair_requires_reason_and_moved_case_can_only_close_without_inventory_movement(): void
    {
        [$owner, $case] = $this->foundation();
        $case->forceFill(['status' => WarrantyRepairStatus::UnderInspection])->save();

        try {
            app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::CannotRepair, $owner);
            $this->fail('Cannot Repair accepted without a reason.');
        } catch (WarrantyRepairException $exception) {
            $this->assertSame('A reason is required when a Warranty / Repair cannot be repaired.', $exception->getMessage());
        }

        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::CannotRepair, $owner, 'Repair is not viable');
        $this->assertSame([], app(WarrantyRepairLifecycleService::class)->validTransitions($case));
        $case->forceFill(['moved_to_damaged_at' => now(), 'moved_to_damaged_quantity' => 1])->save();
        $movementCount = $case->getConnection()->table('stock_movements')->count();
        $eventCount = $case->statusEvents()->count();
        $this->actingAs($owner);

        Livewire::test(ViewWarrantyRepair::class, ['record' => $case->getRouteKey()])
            ->assertActionVisible('next_completed')
            ->assertActionHidden('next_send_to_technician')
            ->assertActionHidden('moveToDamaged');

        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::Completed, $owner);
        $this->assertSame(WarrantyRepairStatus::Completed, $case->status);
        $this->assertSame($movementCount, $case->getConnection()->table('stock_movements')->count());
        $this->assertSame($eventCount + 1, $case->statusEvents()->count());
    }

    public function test_invalid_direct_transition_remains_blocked(): void
    {
        [$owner, $case] = $this->foundation();

        $this->expectException(WarrantyRepairException::class);
        app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::DispatchedBack, $owner);
    }

    public function test_external_warranty_case_retains_dispatch_back_step(): void
    {
        [$owner, $case] = $this->foundation();
        $case->forceFill(['status' => WarrantyRepairStatus::QcPending])->save();

        $case = app(WarrantyRepairService::class)->transition($case, WarrantyRepairStatus::ReadyToReturn, $owner);

        $this->assertSame(WarrantyRepairStatus::ReadyToReturn, $case->status);
        $this->assertTrue(app(WarrantyRepairLifecycleService::class)->allows($case, WarrantyRepairStatus::DispatchedBack));
        $this->actingAs($owner);
        Livewire::test(ViewWarrantyRepair::class, ['record' => $case->getRouteKey()])
            ->assertActionVisible('next_dispatched_back');
    }

    public function test_received_date_correction_is_authorized_audited_and_cannot_break_chronology(): void
    {
        [$owner, $case] = $this->foundation();
        $case->forceFill([
            'status' => WarrantyRepairStatus::Completed,
            'repair_completed_at' => now()->subDays(3),
            'received_back_at' => now()->subDays(2),
            'qc_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ])->save();

        try {
            app(WarrantyRepairService::class)->correctReceivedAt($case, now(), 'Incorrect intake date', $owner);
            $this->fail('A received date after downstream lifecycle dates was accepted.');
        } catch (WarrantyRepairException $exception) {
            $this->assertSame('Repair Completed cannot be earlier than Received At.', $exception->getMessage());
        }

        $corrected = app(WarrantyRepairService::class)->correctReceivedAt(
            $case,
            now()->subDays(4),
            'Correcting the recorded intake date from the source document.',
            $owner,
        );

        $this->assertTrue($corrected->received_at->isSameDay(now()->subDays(4)));
        $log = DB::table('activity_logs')->where('event', 'warranty.received_at_corrected')->sole();
        $properties = json_decode($log->properties, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($case->reference, $properties['warranty_reference']);
        $this->assertArrayHasKey('old_received_at', $properties);
        $this->assertArrayHasKey('new_received_at', $properties);
        $this->assertSame('Correcting the recorded intake date from the source document.', $properties['reason']);
    }

    public function test_manager_cannot_use_received_date_correction_and_days_open_never_displays_negative(): void
    {
        [$owner, $case] = $this->foundation();
        $manager = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);
        $case->forceFill(['completed_at' => now()->subDays(8), 'received_at' => now()->subDay()])->save();

        Livewire::actingAs($owner)->test(ListWarrantyRepairs::class)->assertSee('0 days');

        $this->expectException(AuthorizationException::class);
        app(WarrantyRepairService::class)->correctReceivedAt($case, now()->subDays(9), 'Not allowed', $manager);
    }

    private function foundation(): array
    {
        $owner = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $case = WarrantyRepair::query()->create([
            'reference' => 'WR-2026-991001',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'source' => WarrantyRepairSource::Manual,
            'issue_description' => 'Guided workflow test',
            'received_at' => now()->subDay(),
            'status' => WarrantyRepairStatus::Received,
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $owner->id,
        ]);

        return [$owner, $case];
    }
}
