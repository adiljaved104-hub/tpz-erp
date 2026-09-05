<?php

namespace Tests\Feature\Hr;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BiometricAttendanceSyncRunMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_run_schema_has_expected_defaults_indexes_and_nullable_initiator(): void
    {
        $this->assertTrue(Schema::hasTable('biometric_attendance_sync_runs'));
        $expectedColumns = [
            'id', 'source', 'device_identifier', 'mode', 'window_from', 'window_to',
            'status', 'attempted_at', 'completed_at', 'initiated_by_user_id',
            'imported_count', 'duplicate_count', 'skipped_count', 'unmapped_count',
            'safe_error_code', 'safe_error_message', 'idempotency_key', 'created_at', 'updated_at',
        ];
        $actualColumns = collect(DB::select("PRAGMA table_info('biometric_attendance_sync_runs')"))
            ->pluck('name')
            ->all();
        $this->assertEqualsCanonicalizing($expectedColumns, $actualColumns);
        $this->assertSame(0, DB::table('biometric_attendance_sync_runs')->count());

        DB::table('biometric_attendance_sync_runs')->insert($this->validRun());

        $run = DB::table('biometric_attendance_sync_runs')->sole();
        $this->assertNull($run->initiated_by_user_id);
        $this->assertNull($run->completed_at);
        $this->assertSame(0, $run->imported_count);
        $this->assertSame(0, $run->duplicate_count);
        $this->assertSame(0, $run->skipped_count);
        $this->assertSame(0, $run->unmapped_count);

        $indexNames = collect(DB::select("PRAGMA index_list('biometric_attendance_sync_runs')"))
            ->pluck('name');

        $this->assertContains('biometric_sync_runs_source_device_status_index', $indexNames);
        $this->assertContains('biometric_sync_runs_source_device_window_index', $indexNames);
        $this->assertContains('biometric_sync_runs_status_attempted_index', $indexNames);
        $hasUniqueIdempotencyIndex = collect(DB::select("PRAGMA index_list('biometric_attendance_sync_runs')"))
            ->contains(function (object $index): bool {
                if ((int) $index->unique !== 1) {
                    return false;
                }

                return collect(DB::select("PRAGMA index_info('{$index->name}')"))
                    ->pluck('name')
                    ->all() === ['idempotency_key'];
            });
        $this->assertTrue($hasUniqueIdempotencyIndex);
    }

    public function test_controlled_values_window_and_non_negative_counts_are_database_enforced(): void
    {
        foreach ([
            ['mode' => 'automatic'],
            ['status' => 'completed'],
            ['window_from' => '2026-08-21 11:00:00', 'window_to' => '2026-08-21 10:00:00'],
            ['imported_count' => -1],
            ['duplicate_count' => -1],
            ['skipped_count' => -1],
            ['unmapped_count' => -1],
        ] as $overrides) {
            $this->expectQueryFailure(fn () => DB::table('biometric_attendance_sync_runs')->insert(
                array_merge($this->validRun(), $overrides, ['idempotency_key' => (string) Str::uuid()]),
            ));
        }
    }

    public function test_initiator_foreign_key_is_nullable_and_restrictive(): void
    {
        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('biometric_attendance_sync_runs')"))
            ->firstWhere('from', 'initiated_by_user_id');

        $this->assertNotNull($foreignKey);
        $this->assertSame('users', $foreignKey->table);
        $this->assertSame('id', $foreignKey->to);
        $this->assertSame('RESTRICT', strtoupper($foreignKey->on_delete));

        $initiator = collect(DB::select("PRAGMA table_info('biometric_attendance_sync_runs')"))
            ->firstWhere('name', 'initiated_by_user_id');
        $this->assertSame(0, $initiator->notnull);
    }

    private function validRun(array $overrides = []): array
    {
        return array_merge([
            'source' => 'hikvision_isapi',
            'device_identifier' => 'office-main',
            'mode' => 'manual',
            'window_from' => '2026-08-21 09:00:00',
            'window_to' => '2026-08-21 10:00:00',
            'status' => 'running',
            'attempted_at' => '2026-08-21 10:01:00',
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function expectQueryFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
