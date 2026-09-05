<?php

namespace Tests\Feature\Returns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerReturnMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_two_schema_has_required_tables_constraints_and_empty_sequence(): void
    {
        foreach (['customer_returns', 'customer_return_items', 'customer_return_status_events', 'customer_return_inspections'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $itemSql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'customer_return_items')->value('sql');
        $inspectionSql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'customer_return_inspections')->value('sql');
        $this->assertStringContainsString('return_quantity > 0', $itemSql);
        $this->assertStringContainsString("result IN ('sellable','damaged')", $inspectionSql);
        $this->assertDatabaseHas('reference_sequences', ['key' => 'customer_return:'.now()->year, 'next_value' => 1]);
        $this->assertDatabaseCount('customer_returns', 0);
    }

    public function test_empty_phase_two_schema_rolls_back_cleanly_with_foreign_keys_enabled(): void
    {
        foreach ([
            '2026_08_12_063129_initialize_customer_return_reference_sequence.php',
            '2026_08_12_063127_create_customer_return_inspections_table.php',
            '2026_08_12_063126_create_customer_return_status_events_table.php',
            '2026_08_12_063124_create_customer_return_items_table.php',
            '2026_08_12_063122_create_customer_returns_table.php',
        ] as $migration) {
            (require database_path("migrations/{$migration}"))->down();
        }

        $this->assertFalse(Schema::hasTable('customer_returns'));
        $this->assertFalse(Schema::hasTable('customer_return_inspections'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
