<?php

namespace Tests\Feature\Qc;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Tests\TestCase;

class QcMigrationTriggerPrivilegeTest extends TestCase
{
    public function test_mysql_creates_both_guards_when_trigger_privileges_are_available(): void
    {
        $this->mockSchema('mysql');
        foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $operation => $suffix) {
            DB::shouldReceive('unprepared')->once()->with("CREATE TRIGGER qcc_{$suffix}_guard BEFORE {$operation} ON qc_certificates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QC certificates are immutable'")->andReturn(true);
        }
        $this->migration()->up();
        $this->assertTrue(true);
    }

    public function test_mysql_binary_logging_privilege_error_1419_does_not_fail_migration(): void
    {
        $this->mockSchema('mysql');
        DB::shouldReceive('unprepared')->twice()->andThrow($this->queryException(1419));
        $this->migration()->up();
        $this->assertTrue(true);
    }

    public function test_mysql_keeps_a_successful_guard_when_the_other_guard_gets_1419(): void
    {
        $this->mockSchema('mysql');
        DB::shouldReceive('unprepared')->once()->withArgs(fn (string $sql): bool => str_contains($sql, 'qcc_update_guard'))->andReturn(true);
        DB::shouldReceive('unprepared')->once()->withArgs(fn (string $sql): bool => str_contains($sql, 'qcc_delete_guard'))->andThrow($this->queryException(1419));
        $this->migration()->up();
        $this->assertTrue(true);
    }

    public function test_other_mysql_privilege_errors_are_not_suppressed(): void
    {
        $this->mockSchema('mysql');
        DB::shouldReceive('unprepared')->once()->andThrow($this->queryException(1227));
        $this->expectException(QueryException::class);
        $this->migration()->up();
    }

    public function test_mysql_syntax_errors_are_not_suppressed(): void
    {
        $this->mockSchema('mysql');
        DB::shouldReceive('unprepared')->once()->andThrow($this->queryException(1064));
        $this->expectException(QueryException::class);
        $this->migration()->up();
    }

    public function test_missing_mysql_native_error_code_is_not_suppressed(): void
    {
        $this->mockSchema('mysql');
        DB::shouldReceive('unprepared')->once()->andThrow(new QueryException('mysql', 'CREATE TRIGGER', [], new PDOException('Trigger creation failed', 1419)));
        $this->expectException(QueryException::class);
        $this->migration()->up();
    }

    public function test_sqlite_trigger_errors_are_never_suppressed(): void
    {
        $this->mockSchema('sqlite');
        DB::shouldReceive('unprepared')->once()->andThrow($this->queryException(1419));
        $this->expectException(QueryException::class);
        $this->migration()->up();
    }

    private function mockSchema(string $driver): void
    {
        Schema::shouldReceive('create')->times(5);
        DB::shouldReceive('getDriverName')->andReturn($driver);
    }

    private function queryException(int $code): QueryException
    {
        $previous = new PDOException('Synthetic trigger error');
        $previous->errorInfo = ['HY000', $code, 'Synthetic trigger error'];

        return new QueryException('mysql', 'CREATE TRIGGER', [], $previous);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_06_090000_create_qc_device_passports.php');
    }
}
