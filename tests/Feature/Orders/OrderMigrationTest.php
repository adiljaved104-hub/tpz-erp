<?php

namespace Tests\Feature\Orders;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\InventoryReservation;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_a_schema_has_required_links_constraints_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('orders', [
            'reference', 'status', 'warehouse_id', 'marketplace_platform_id', 'external_order_number',
            'external_identity_hash', 'order_date', 'handled_by_employee_id', 'idempotency_key',
            'cancellation_idempotency_key',
        ]));
        $this->assertTrue(Schema::hasColumns('order_items', ['order_id', 'product_id', 'ordered_quantity', 'selling_price', 'line_total']));
        $this->assertTrue(Schema::hasColumn('inventory_reservations', 'order_item_id'));
        $this->assertTrue(Schema::hasTable('order_status_events'));
        $this->assertTrue(Schema::hasTable('order_fulfillments'));
        $this->assertTrue(Schema::hasColumns('order_fulfillment_items', [
            'order_fulfillment_id', 'order_item_id', 'product_inventory_id', 'quantity', 'inventory_unit_cost', 'cogs_total', 'posting_key',
        ]));

        if (DB::getDriverName() === 'sqlite') {
            $orderSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'orders')->value('sql');
            $itemSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'order_items')->value('sql');
            $reservationSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'inventory_reservations')->value('sql');
            $this->assertStringContainsString("status IN ('draft', 'pending_review', 'confirmed', 'reserved', 'processing', 'fulfilled', 'cancelled')", $orderSql);
            $this->assertStringContainsString('ordered_quantity > 0', $itemSql);
            $this->assertStringContainsString('order_item_id', $reservationSql);
            $this->assertStringContainsString("status IN ('active', 'released', 'fulfilled')", $reservationSql);
            $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($reservationSql));
            $fulfillmentItemSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'order_fulfillment_items')->value('sql');
            $this->assertStringContainsString('quantity > 0', $fulfillmentItemSql);
            $this->assertStringContainsString('inventory_unit_cost >= 0', $fulfillmentItemSql);
        }
    }

    public function test_sales_order_sequence_is_ready_without_allocating_an_order(): void
    {
        $this->assertDatabaseHas('reference_sequences', ['key' => 'sales_order:'.now()->year, 'next_value' => 1]);
        $this->assertDatabaseHas('reference_sequences', ['key' => 'order_fulfillment:'.now()->year, 'next_value' => 1]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_sqlite_reservation_rebuild_preserves_existing_active_reservations_exactly(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific rebuild verification.');
        }

        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);
        $inventory = ProductInventory::factory()->create(['available_quantity' => 5, 'reserved_quantity' => 2]);
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => 2,
            'status' => 'active',
            'reserved_by_user_id' => $user->id,
        ]);
        $before = DB::table('inventory_reservations')->where('id', $reservation->id)->first();
        $migration = require database_path('migrations/2026_08_11_044047_extend_inventory_reservations_for_order_lifecycle.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('inventory_reservations', 'order_item_id'));
        $migration->up();

        $after = DB::table('inventory_reservations')->where('id', $reservation->id)->first();
        $this->assertTrue(Schema::hasColumn('inventory_reservations', 'order_item_id'));
        foreach (['id', 'reference', 'product_inventory_id', 'product_id', 'warehouse_id', 'quantity', 'status', 'reason', 'idempotency_key', 'reserved_by_user_id', 'reserved_at', 'created_at', 'updated_at'] as $column) {
            $this->assertSame((string) $before->{$column}, (string) $after->{$column}, "Reservation {$column} changed during the rebuild.");
        }
        $this->assertNull($after->order_item_id);
    }
}
