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
        foreach (['marketplace_platforms', 'responsibility_assignments', 'responsibility_assignment_brands', 'responsibility_assignment_platforms', 'responsibility_assignment_products', 'inventory_responsibility_quantities'] as $table) {
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
}
