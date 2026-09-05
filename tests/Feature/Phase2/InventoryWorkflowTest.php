<?php

namespace Tests\Feature\Phase2;

use App\Actions\Inventory\MoveInventoryToDamaged;
use App\Actions\Inventory\PostOpeningStock;
use App\Actions\Inventory\ReleaseInventoryReservation;
use App\Actions\Inventory\ReserveInventory;
use App\Actions\Inventory\RestoreDamagedInventory;
use App\DTOs\Inventory\MoveToDamagedData;
use App\DTOs\Inventory\PostOpeningStockData;
use App\DTOs\Inventory\ReleaseReservationData;
use App\DTOs\Inventory\ReserveInventoryData;
use App\DTOs\Inventory\RestoreDamagedData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductStatus;
use App\Exceptions\DuplicateInventoryPostingException;
use App\Exceptions\InactiveInventorySubjectException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InventoryInvariantException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\OpeningStockEntry;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_stock_posts_available_and_damaged_atomically_and_is_idempotent(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $key = (string) Str::uuid();
        $data = new PostOpeningStockData($product->id, $warehouse->id, 10, 2, '100.0000', 'Initial count', $key);
        $result = app(PostOpeningStock::class)->handle($data, $owner);

        $this->assertSame(10, $result->inventory->available_quantity);
        $this->assertSame(0, $result->inventory->reserved_quantity);
        $this->assertSame(2, $result->inventory->damaged_quantity);
        $this->assertSame(10, $result->inventory->sellableQuantity());
        $this->assertSame(12, $result->inventory->totalOnHand());
        $this->assertSame('100.0000', $result->inventory->average_cost);
        $this->assertCount(2, $result->movements);
        $this->assertSame(1, OpeningStockEntry::query()->count());
        $this->assertSame(1, ProductInventory::query()->count());
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'inventory.opening_stock_posted')->count());

        $retry = app(PostOpeningStock::class)->handle($data, $owner);
        $this->assertSame($result->source->id, $retry->source->id);
        $this->assertSame(1, OpeningStockEntry::query()->count());
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_opening_stock_is_rejected_after_any_history_or_duplicate_pair(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        app(PostOpeningStock::class)->handle($this->openingData($product, $warehouse, 1), $owner);

        $this->expectException(DuplicateInventoryPostingException::class);
        app(PostOpeningStock::class)->handle($this->openingData($product, $warehouse, 2), $owner);
    }

    public function test_reserve_release_damage_and_restore_preserve_average_and_total(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inventory = app(PostOpeningStock::class)->handle($this->openingData($product, $warehouse, 10), $owner)->inventory;
        $reservation = app(ReserveInventory::class)->handle(new ReserveInventoryData($inventory->id, 3, 'Allocation', (string) Str::uuid()), $owner)->source;

        $inventory->refresh();
        $this->assertSame(10, $inventory->available_quantity);
        $this->assertSame(3, $inventory->reserved_quantity);
        $this->assertSame(7, $inventory->sellableQuantity());
        $this->assertSame('10.0000', $inventory->average_cost);

        $releaseKey = (string) Str::uuid();
        app(ReleaseInventoryReservation::class)->handle($reservation, new ReleaseReservationData('No longer needed', $releaseKey), $owner);
        app(ReleaseInventoryReservation::class)->handle($reservation->refresh(), new ReleaseReservationData('No longer needed', $releaseKey), $owner);
        $this->assertSame(InventoryReservationStatus::Released, $reservation->refresh()->status);
        $this->assertSame(0, $inventory->refresh()->reserved_quantity);

        app(MoveInventoryToDamaged::class)->handle(new MoveToDamagedData($inventory->id, 2, 'Failed inspection', (string) Str::uuid()), $owner);
        $this->assertSame(8, $inventory->refresh()->available_quantity);
        $this->assertSame(2, $inventory->damaged_quantity);
        $this->assertSame(10, $inventory->totalOnHand());
        $this->assertSame('10.0000', $inventory->average_cost);

        app(RestoreDamagedInventory::class)->handle(new RestoreDamagedData($inventory->id, 1, 'Repaired', (string) Str::uuid()), $owner);
        $this->assertSame(9, $inventory->refresh()->available_quantity);
        $this->assertSame(1, $inventory->damaged_quantity);
        $this->assertSame(10, $inventory->totalOnHand());
        $this->assertSame('10.0000', $inventory->average_cost);
    }

    public function test_insufficient_and_invalid_quantities_are_rejected(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inventory = app(PostOpeningStock::class)->handle($this->openingData($product, $warehouse, 2), $owner)->inventory;

        try {
            app(ReserveInventory::class)->handle(new ReserveInventoryData($inventory->id, 3, 'Too much', (string) Str::uuid()), $owner);
            $this->fail('Insufficient reservation should fail.');
        } catch (InsufficientInventoryException) {
            $this->assertSame(0, $inventory->refresh()->reserved_quantity);
        }

        $this->expectException(ValidationException::class);
        app(MoveInventoryToDamaged::class)->handle(new MoveToDamagedData($inventory->id, 0, 'Invalid', (string) Str::uuid()), $owner);
    }

    public function test_inactive_subject_blocks_new_operations_but_allows_cleanup(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inventory = app(PostOpeningStock::class)->handle(new PostOpeningStockData($product->id, $warehouse->id, 5, 2, '10', 'Initial', (string) Str::uuid()), $owner)->inventory;
        $reservation = app(ReserveInventory::class)->handle(new ReserveInventoryData($inventory->id, 1, 'Allocation', (string) Str::uuid()), $owner)->source;
        $product->forceFill(['status' => ProductStatus::Inactive])->save();

        try {
            app(MoveInventoryToDamaged::class)->handle(new MoveToDamagedData($inventory->id, 1, 'Blocked', (string) Str::uuid()), $owner);
            $this->fail('Inactive Product should block new damage classification.');
        } catch (InactiveInventorySubjectException) {
            $this->assertSame(2, $inventory->refresh()->damaged_quantity);
        }

        app(ReleaseInventoryReservation::class)->handle($reservation, new ReleaseReservationData('Cleanup', (string) Str::uuid()), $owner);
        app(RestoreDamagedInventory::class)->handle(new RestoreDamagedData($inventory->id, 1, 'Cleanup', (string) Str::uuid()), $owner);
        $this->assertSame(0, $inventory->refresh()->reserved_quantity);
        $this->assertSame(1, $inventory->damaged_quantity);
    }

    public function test_failed_activity_write_rolls_back_all_business_records(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $this->mock(ActivityLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Simulated activity failure'));

        try {
            app(PostOpeningStock::class)->handle($this->openingData($product, $warehouse, 1), $owner);
            $this->fail('Posting should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated activity failure', $exception->getMessage());
        }

        $this->assertSame(0, OpeningStockEntry::query()->count());
        $this->assertSame(0, ProductInventory::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_stock_movements_and_reservations_cannot_be_deleted_or_modified(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inventory = app(PostOpeningStock::class)->handle($this->openingData($product, $warehouse, 2), $owner)->inventory;
        $reservation = app(ReserveInventory::class)->handle(new ReserveInventoryData($inventory->id, 1, 'Allocation', (string) Str::uuid()), $owner)->source;
        $movement = StockMovement::query()->latest('id')->firstOrFail();

        try {
            $movement->update(['reason' => 'Changed']);
            $this->fail('Movement update should fail.');
        } catch (InventoryInvariantException) {
            $this->assertNotSame('Changed', $movement->fresh()->reason);
        }

        $this->expectException(InventoryInvariantException::class);
        $reservation->delete();
    }

    public function test_activity_logs_have_no_financial_payloads(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        app(PostOpeningStock::class)->handle($this->openingData($product, $warehouse, 1), $owner);
        $payload = ActivityLog::query()->where('event', 'inventory.opening_stock_posted')->firstOrFail()->properties;
        $json = json_encode($payload);

        $this->assertStringNotContainsString('cost', strtolower($json));
        $this->assertStringNotContainsString('value', strtolower($json));
        $this->assertStringNotContainsString('10.0000', $json);
    }

    private function openingData(Product $product, Warehouse $warehouse, int $quantity): PostOpeningStockData
    {
        return new PostOpeningStockData($product->id, $warehouse->id, $quantity, 0, '10.0000', 'Initial count', (string) Str::uuid());
    }

    /** @return array{User, Product, Warehouse} */
    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);

        return [$owner->refresh(), Product::factory()->create(), Warehouse::factory()->create()];
    }
}
