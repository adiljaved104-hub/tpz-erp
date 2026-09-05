<?php

namespace Tests\Feature\Phase1A;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleNormalizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_role_migration_rolls_back_and_reapplies_without_data_loss(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Role Test', 'email' => 'role@example.com', 'password' => 'not-used',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'user_id' => $userId, 'employee_id' => 'TPZ-9001', 'name' => 'Role Test',
            'email' => 'role@example.com', 'password' => null, 'designation' => 'Test',
            'role' => 'owner', 'status' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_06_073407_normalize_employee_roles_on_employees_table.php');
        $migration->down();
        $this->assertSame('Owner', DB::table('employees')->value('role'));
        $this->assertSame($userId, DB::table('employees')->value('user_id'));

        $migration->up();
        $this->assertSame('owner', DB::table('employees')->value('role'));
        $this->assertSame($userId, DB::table('employees')->value('user_id'));
    }

    public function test_role_migration_aborts_on_unknown_values(): void
    {
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('employees')->insert([
            'employee_id' => 'TPZ-9002', 'name' => 'Unknown Role', 'email' => 'unknown@example.com',
            'password' => null, 'designation' => 'Test', 'role' => 'superuser', 'status' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('PRAGMA ignore_check_constraints = OFF');

        $migration = require database_path('migrations/2026_08_06_073407_normalize_employee_roles_on_employees_table.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('superuser');
        $migration->up();
    }
}
