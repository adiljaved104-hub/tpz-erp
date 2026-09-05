<?php

namespace Tests\Feature\Returns;

use App\Models\MarketplacePlatform;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ReturnFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_constrained_and_empty(): void
    {
        foreach (['return_handling_mode', 'default_return_receiving_warehouse_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('marketplace_platforms', $column));
        }
        foreach (['marketplace_non_sellable_quantity', 'marketplace_non_sellable_value', 'qc_pending_quantity', 'qc_pending_value'] as $column) {
            $this->assertTrue(Schema::hasColumn('product_inventories', $column));
        }
        $this->assertTrue(Schema::hasTable('stock_movement_bucket_changes'));
        $this->assertSame(0, DB::table('stock_movement_bucket_changes')->count());

        $inventorySql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'product_inventories')->value('sql');
        $bucketSql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'stock_movement_bucket_changes')->value('sql');
        $this->assertStringContainsString('marketplace_non_sellable_quantity >= 0', $inventorySql);
        $this->assertStringContainsString('marketplace_non_sellable_quantity > 0 OR marketplace_non_sellable_value = 0', $inventorySql);
        $this->assertStringContainsString('quantity_after = quantity_before + quantity_delta', $bucketSql);
        $this->assertStringContainsString("'marketplace_non_sellable', 'qc_pending'", $bucketSql);

        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('stock_movement_bucket_changes')"));
        $this->assertSame(['stock_movements'], $foreignKeys->pluck('table')->all());
        $this->assertSame(['RESTRICT'], $foreignKeys->pluck('on_delete')->unique()->values()->all());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_existing_records_receive_only_zero_and_null_defaults(): void
    {
        $platform = MarketplacePlatform::factory()->create();
        $inventory = ProductInventory::factory()->create([
            'available_quantity' => 12,
            'reserved_quantity' => 7,
            'damaged_quantity' => 2,
            'average_cost' => '1217.1428',
        ]);

        $this->assertNull($platform->return_handling_mode);
        $this->assertNull($platform->default_return_receiving_warehouse_id);
        $this->assertSame(12, $inventory->available_quantity);
        $this->assertSame(7, $inventory->reserved_quantity);
        $this->assertSame(2, $inventory->damaged_quantity);
        $this->assertSame('1217.1428', $inventory->average_cost);
        $this->assertSame(0, $inventory->marketplace_non_sellable_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
    }

    public function test_rollback_guards_refuse_meaningful_configuration_custody_and_history(): void
    {
        $platform = MarketplacePlatform::factory()->create(['return_handling_mode' => 'company_direct_return']);
        $inventory = ProductInventory::factory()->create([
            'marketplace_non_sellable_quantity' => 1,
            'marketplace_non_sellable_value' => '0.0000',
        ]);
        $movement = StockMovement::factory()->create();
        DB::table('stock_movement_bucket_changes')->insert([
            'stock_movement_id' => $movement->id,
            'bucket' => 'qc_pending',
            'quantity_delta' => 1,
            'quantity_before' => 0,
            'quantity_after' => 1,
            'value_delta' => 0,
            'value_before' => 0,
            'value_after' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            '2026_08_12_053033_add_return_configuration_to_marketplace_platforms.php' => 'Marketplace Return configuration is in use',
            '2026_08_12_053046_add_return_custody_buckets_to_product_inventories.php' => 'Return custody balances are in use',
            '2026_08_12_053052_create_stock_movement_bucket_changes_table.php' => 'custody bucket history exists',
        ] as $migration => $message) {
            try {
                (require database_path("migrations/{$migration}"))->down();
                $this->fail("Rollback guard {$migration} should have refused.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertTrue($platform->exists);
        $this->assertTrue($inventory->exists);
        $this->assertDatabaseCount('stock_movement_bucket_changes', 1);
    }

    public function test_empty_foundation_rolls_back_without_disabling_sqlite_foreign_keys(): void
    {
        (require database_path('migrations/2026_08_12_053052_create_stock_movement_bucket_changes_table.php'))->down();
        (require database_path('migrations/2026_08_12_053046_add_return_custody_buckets_to_product_inventories.php'))->down();
        (require database_path('migrations/2026_08_12_053033_add_return_configuration_to_marketplace_platforms.php'))->down();

        $this->assertFalse(Schema::hasTable('stock_movement_bucket_changes'));
        $this->assertFalse(Schema::hasColumn('product_inventories', 'marketplace_non_sellable_quantity'));
        $this->assertFalse(Schema::hasColumn('marketplace_platforms', 'return_handling_mode'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
