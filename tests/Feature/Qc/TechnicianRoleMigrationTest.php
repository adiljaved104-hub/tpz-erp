<?php

namespace Tests\Feature\Qc;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TechnicianRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_employee_schema_preserves_columns_foreign_keys_and_indexes_when_role_constraint_changes(): void
    {
        $migration = require base_path('database/migrations/2026_10_14_090000_add_technician_to_employee_roles.php');
        $columns = Schema::getColumnListing('employees');
        $columnMetadata = $this->columnMetadata();
        $foreignKeys = $this->foreignKeys();
        $indexes = $this->indexes();

        $migration->down();
        $this->assertSame($columns, Schema::getColumnListing('employees'));
        $this->assertSame($columnMetadata, $this->columnMetadata());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertSame($indexes, $this->indexes());
        $downSchema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'employees'");
        $this->assertStringNotContainsString('technician', strtolower((string) $downSchema->sql));

        $migration->up();
        $this->assertSame($columns, Schema::getColumnListing('employees'));
        $this->assertSame($columnMetadata, $this->columnMetadata());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertSame($indexes, $this->indexes());

        $this->assertContains('user_id', $columns);
        $this->assertContains('team_id', $columns);
        $this->assertContains('employee_id', $columns);
        $roleColumn = collect($this->columnMetadata())->firstWhere('name', 'role');
        $this->assertSame(1, $roleColumn['not_null']);
        $this->assertSame('staff', trim((string) $roleColumn['default'], "'\""));
        $employee = $this->technician();
        $this->assertSame(EmployeeRole::Technician, $employee->fresh()->role);
        $schema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'employees'");
        $this->assertStringContainsString('technician', strtolower((string) $schema->sql));
    }

    public function test_down_migration_refuses_to_remove_role_while_technician_employees_exist(): void
    {
        $employee = $this->technician();
        $migration = require base_path('database/migrations/2026_10_14_090000_add_technician_to_employee_roles.php');
        $columns = Schema::getColumnListing('employees');
        $foreignKeys = $this->foreignKeys();
        $indexes = $this->indexes();

        try {
            $migration->down();
            $this->fail('Rollback must refuse while Technician employees exist.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Technician employees still exist', $exception->getMessage());
        }

        $this->assertSame($columns, Schema::getColumnListing('employees'));
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertSame($indexes, $this->indexes());
        $this->assertSame(EmployeeRole::Technician, $employee->fresh()->role);
    }

    /** @return array<int, array<string, mixed>> */
    private function columnMetadata(): array
    {
        return collect(DB::select('PRAGMA table_info(employees)'))
            ->map(fn ($row): array => [
                'name' => $row->name,
                'type' => strtolower($row->type),
                'not_null' => (int) $row->notnull,
                'default' => $row->dflt_value,
                'primary_key_position' => (int) $row->pk,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function foreignKeys(): array
    {
        return collect(DB::select('PRAGMA foreign_key_list(employees)'))
            ->map(fn ($row): array => [
                'sequence' => (int) $row->seq,
                'table' => $row->table,
                'from' => $row->from,
                'to' => $row->to,
                'on_update' => strtoupper($row->on_update),
                'on_delete' => strtoupper($row->on_delete),
                'match' => $row->match,
            ])
            ->sortBy(fn (array $row): string => $row['table'].$row['from'].$row['sequence'])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function indexes(): array
    {
        return collect(DB::select('PRAGMA index_list(employees)'))
            ->map(function ($row): array {
                $name = str_replace('"', '""', $row->name);
                $columns = DB::select('PRAGMA index_info("'.$name.'")');

                return [
                    'name' => $row->name,
                    'unique' => (int) $row->unique,
                    'origin' => $row->origin,
                    'partial' => (int) $row->partial,
                    'columns' => collect($columns)->map(fn ($column): ?string => $column->name)->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function technician(): Employee
    {
        $email = str()->uuid().'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);

        return Employee::factory()->create(['user_id' => $user->id, 'email' => $email, 'role' => EmployeeRole::Technician]);
    }
}
