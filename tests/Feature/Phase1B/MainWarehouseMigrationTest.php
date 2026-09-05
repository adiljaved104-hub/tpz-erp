<?php

namespace Tests\Feature\Phase1B;

use App\Models\ActivityLog;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MainWarehouseMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_exact_main_warehouse_without_activity_log(): void
    {
        $main = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

        $this->assertSame('Main Warehouse', $main->name);
        $this->assertTrue($main->status);
        $this->assertTrue($main->is_default);
        $this->assertNull($main->address);
        $this->assertDatabaseCount('warehouses', 1);
        $this->assertSame(0, ActivityLog::query()->where('event', 'warehouse.main_created')->count());
    }

    public function test_migration_is_idempotent_for_exact_record(): void
    {
        $migration = require database_path('migrations/2026_08_06_092118_ensure_main_warehouse_exists.php');
        $migration->up();

        $this->assertSame(1, Warehouse::query()->where('code', 'MAIN')->count());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_migration_aborts_on_conflicting_main(): void
    {
        DB::table('warehouses')->where('code', 'MAIN')->update(['name' => 'Conflict']);
        $migration = require database_path('migrations/2026_08_06_092118_ensure_main_warehouse_exists.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicting approved values');
        $migration->up();
    }

    public function test_migration_aborts_on_unexpected_active_default(): void
    {
        Warehouse::factory()->create(['is_default' => true]);
        $migration = require database_path('migrations/2026_08_06_092118_ensure_main_warehouse_exists.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('another active default');
        $migration->up();
    }
}
