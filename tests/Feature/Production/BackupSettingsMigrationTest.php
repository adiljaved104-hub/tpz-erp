<?php

namespace Tests\Feature\Production;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_settings_schema_is_additive_singleton_and_uses_safe_defaults(): void
    {
        $this->assertTrue(Schema::hasTable('backup_settings'));
        $this->assertSame([
            'id', 'enabled', 'backup_time', 'database_enabled', 'storage_enabled',
            'daily_retention', 'weekly_retention', 'monthly_retention', 'backup_disk',
            'offsite_enabled', 'encryption_enabled', 'updated_by_user_id', 'created_at', 'updated_at',
        ], Schema::getColumnListing('backup_settings'));
        $this->assertDatabaseCount('backup_settings', 0);

        DB::table('backup_settings')->insert(['id' => 1]);
        $settings = DB::table('backup_settings')->sole();

        $this->assertSame(0, (int) $settings->enabled);
        $this->assertSame('02:00', $settings->backup_time);
        $this->assertSame(1, (int) $settings->database_enabled);
        $this->assertSame(1, (int) $settings->storage_enabled);
        $this->assertSame(14, (int) $settings->daily_retention);
        $this->assertSame(8, (int) $settings->weekly_retention);
        $this->assertSame(6, (int) $settings->monthly_retention);
        $this->assertSame('local', $settings->backup_disk);
    }

    public function test_singleton_component_retention_time_and_user_constraints_are_enforced(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['id' => 2],
            ['id' => 1, 'enabled' => 1, 'database_enabled' => 0, 'storage_enabled' => 0],
            ['id' => 1, 'backup_time' => '25:90'],
            ['id' => 1, 'daily_retention' => 0],
            ['id' => 1, 'weekly_retention' => 105],
            ['id' => 1, 'monthly_retention' => 61],
            ['id' => 1, 'updated_by_user_id' => $user->id + 999],
        ] as $invalid) {
            try {
                DB::table('backup_settings')->insert($invalid);
                $this->fail('Invalid Backup Settings were accepted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('backup_settings', 0);
            }
        }

        DB::table('backup_settings')->insert([
            'id' => 1,
            'enabled' => 1,
            'database_enabled' => 1,
            'storage_enabled' => 0,
            'updated_by_user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);
        $user->delete();
    }
}
