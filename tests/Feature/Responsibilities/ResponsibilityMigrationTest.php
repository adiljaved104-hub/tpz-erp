<?php

namespace Tests\Feature\Responsibilities;

use App\Models\MarketplacePlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ResponsibilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_schema_has_all_tables_indexes_and_foreign_keys_without_triggers(): void
    {
        foreach (['marketplace_platforms', 'responsibility_assignments', 'responsibility_assignment_brands', 'responsibility_assignment_categories', 'responsibility_assignment_platforms', 'responsibility_assignment_products', 'responsibility_assignment_warehouses', 'inventory_responsibility_quantities', 'responsibility_inventory_consumptions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumns('responsibility_assignments', ['reference', 'employee_id', 'team_id_at_assignment', 'team_name_at_assignment', 'assignment_mode', 'status', 'active_fingerprint', 'effective_at', 'ended_at', 'assigned_by_user_id', 'ended_by_user_id', 'predecessor_assignment_id', 'idempotency_key', 'reason', 'notes']));
        $this->assertSame(0, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', '%responsibility%')->count());
        $this->assertSame(0, count(DB::select('PRAGMA foreign_key_check')));
        $this->assertDatabaseHas('reference_sequences', ['key' => 'responsibility_assignment', 'next_value' => 1]);
    }

    public function test_platform_rollback_refuses_operational_history(): void
    {
        MarketplacePlatform::factory()->create();
        $migration = require database_path('migrations/2026_08_10_100725_create_marketplace_platforms_table.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_category_and_consumption_foundation_has_restrictive_schema_and_clean_rollback(): void
    {
        $categorySql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'responsibility_assignment_categories')->value('sql');
        $consumptionSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'responsibility_inventory_consumptions')->value('sql');
        $consumptionIndexes = collect(DB::select("PRAGMA index_list('responsibility_inventory_consumptions')"))->pluck('name');

        $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($categorySql));
        $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($consumptionSql));
        $this->assertStringContainsString('inventory_reservation_id IS NOT NULL AND order_fulfillment_item_id IS NULL', $consumptionSql);
        $this->assertTrue($consumptionIndexes->contains('responsibility_consumptions_assignment_idx'));
        $this->assertSame(2, $consumptionIndexes->filter(fn (string $name): bool => str_starts_with($name, 'sqlite_autoindex_responsibility_inventory_consumptions_'))->count());
        $this->assertDatabaseCount('responsibility_assignment_categories', 0);
        $this->assertDatabaseCount('responsibility_inventory_consumptions', 0);

        $migration = require database_path('migrations/2026_09_09_150000_add_responsibility_categories_and_reservation_attribution.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('responsibility_assignment_categories'));
        $this->assertFalse(Schema::hasTable('responsibility_inventory_consumptions'));
    }

    public function test_warehouse_scope_has_restrictive_schema_index_and_clean_empty_rollback(): void
    {
        $warehouseSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'responsibility_assignment_warehouses')->value('sql');
        $warehouseIndexes = collect(DB::select("PRAGMA index_list('responsibility_assignment_warehouses')"))->pluck('name');

        $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($warehouseSql));
        $this->assertTrue($warehouseIndexes->contains('ra_warehouses_warehouse_idx'));
        $this->assertDatabaseCount('responsibility_assignment_warehouses', 0);

        $migration = require database_path('migrations/2026_09_16_090000_create_responsibility_assignment_warehouses.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('responsibility_assignment_warehouses'));
    }

    public function test_condition_scope_and_ui_preferences_have_restrictive_additive_schema(): void
    {
        $conditionSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'responsibility_assignment_conditions')->value('sql');
        $preferenceSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'user_ui_preferences')->value('sql');

        $this->assertTrue(Schema::hasColumns('responsibility_assignment_conditions', ['assignment_id', 'product_condition']));
        $this->assertTrue(Schema::hasColumns('user_ui_preferences', ['user_id', 'preference_key', 'preference_value']));
        $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($conditionSql));
        $this->assertStringContainsString("'new','renewed','used','open_box','refurbished'", str_replace(' ', '', $conditionSql));
        $this->assertStringContainsString('ON DELETE RESTRICT', strtoupper($preferenceSql));
        $this->assertStringContainsString('JSON_VALID', strtoupper($preferenceSql));
        $this->assertDatabaseCount('responsibility_assignment_conditions', 0);
        $this->assertDatabaseCount('user_ui_preferences', 0);
    }
}
