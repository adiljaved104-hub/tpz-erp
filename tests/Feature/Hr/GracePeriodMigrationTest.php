<?php

namespace Tests\Feature\Hr;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GracePeriodMigrationTest extends TestCase
{
    public function test_additive_grace_migration_backfills_populated_schedules_and_attendance_without_changing_history(): void
    {
        $migrationConnection = 'phase_b_grace_migration_test';
        $previousConnection = DB::getDefaultConnection();
        config([
            "database.connections.{$migrationConnection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge($migrationConnection);
        DB::setDefaultConnection($migrationConnection);

        try {
            Schema::create('attendance_policies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedSmallInteger('grace_minutes')->nullable();
            });
            Schema::create('work_schedules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('attendance_policy_id')->nullable();
                $table->string('name');
            });
            Schema::create('work_schedule_assignments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('work_schedule_id')->nullable();
            });
            Schema::create('employee_attendances', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('attendance_policy_id')->nullable();
                $table->string('status');
                $table->dateTime('first_check_in_at')->nullable();
            });

            DB::table('attendance_policies')->insert([
                ['id' => 1, 'grace_minutes' => 5],
                ['id' => 2, 'grace_minutes' => 15],
            ]);
            DB::table('work_schedules')->insert([
                ['id' => 1, 'attendance_policy_id' => 1, 'name' => 'Standard'],
                ['id' => 2, 'attendance_policy_id' => 2, 'name' => 'Special'],
                ['id' => 3, 'attendance_policy_id' => null, 'name' => 'Legacy without policy'],
            ]);
            DB::table('work_schedule_assignments')->insert(['id' => 1, 'work_schedule_id' => 1]);
            DB::table('employee_attendances')->insert([
                ['id' => 1, 'attendance_policy_id' => 1, 'status' => 'present', 'first_check_in_at' => '2026-08-20 04:02:00'],
                ['id' => 2, 'attendance_policy_id' => 2, 'status' => 'late', 'first_check_in_at' => '2026-08-20 04:16:00'],
                ['id' => 3, 'attendance_policy_id' => null, 'status' => 'absent', 'first_check_in_at' => null],
            ]);

            $migration = require database_path('migrations/2026_10_20_090000_add_effective_schedule_grace_snapshots.php');
            $migration->up();

            $this->assertTrue(Schema::hasColumn('work_schedules', 'grace_minutes'));
            $this->assertTrue(Schema::hasColumn('work_schedule_assignments', 'grace_minutes_override'));
            $this->assertTrue(Schema::hasColumn('employee_attendances', 'grace_minutes'));
            $this->assertSame([5, 15, null], DB::table('work_schedules')->orderBy('id')->pluck('grace_minutes')->all());
            $this->assertSame([5, 15, null], DB::table('employee_attendances')->orderBy('id')->pluck('grace_minutes')->all());
            $this->assertSame(3, DB::table('employee_attendances')->count());
            $this->assertSame(['present', 'late', 'absent'], DB::table('employee_attendances')->orderBy('id')->pluck('status')->all());
            $this->assertSame('2026-08-20 04:16:00', DB::table('employee_attendances')->where('id', 2)->value('first_check_in_at'));
            $this->assertSame(1, DB::table('work_schedule_assignments')->count());

            try {
                $migration->down();
                $this->fail('The historical grace migration unexpectedly allowed a destructive rollback.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('cannot be rolled back safely', $exception->getMessage());
            }
            $this->assertSame(3, DB::table('employee_attendances')->count());
        } finally {
            DB::setDefaultConnection($previousConnection);
            DB::purge($migrationConnection);
            config(["database.connections.{$migrationConnection}" => null]);
        }
    }
}
