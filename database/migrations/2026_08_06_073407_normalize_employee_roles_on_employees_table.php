<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const TO_LOWER = [
        'Owner' => 'owner',
        'Admin' => 'admin',
        'Manager' => 'manager',
        'Staff' => 'staff',
        'owner' => 'owner',
        'admin' => 'admin',
        'manager' => 'manager',
        'staff' => 'staff',
    ];

    /** @var array<string, string> */
    private const TO_TITLE = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'manager' => 'Manager',
        'staff' => 'Staff',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertKnownValues(array_keys(self::TO_LOWER));

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(self::TO_LOWER, 'staff');

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Employee role normalization supports only the approved SQLite and MySQL environments.');
        }

        // MySQL's normal case-insensitive collations reject ENUM definitions that
        // contain both title-case and lowercase variants of the same value.
        DB::statement("ALTER TABLE employees MODIFY role VARCHAR(20) NOT NULL DEFAULT 'staff'");
        $this->mapValues(self::TO_LOWER);
        DB::statement("ALTER TABLE employees MODIFY role ENUM('owner','admin','manager','staff') NOT NULL DEFAULT 'staff'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertKnownValues(array_keys(self::TO_TITLE));

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(self::TO_TITLE, 'Staff');

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Employee role rollback supports only the approved SQLite and MySQL environments.');
        }

        DB::statement("ALTER TABLE employees MODIFY role VARCHAR(20) NOT NULL DEFAULT 'Staff'");
        $this->mapValues(self::TO_TITLE);
        DB::statement("ALTER TABLE employees MODIFY role ENUM('Owner','Admin','Manager','Staff') NOT NULL DEFAULT 'Staff'");
    }

    /** @param array<int, string> $allowed */
    private function assertKnownValues(array $allowed): void
    {
        $unknown = DB::table('employees')
            ->distinct()
            ->pluck('role')
            ->filter(fn (mixed $role): bool => ! in_array($role, $allowed, true))
            ->values()
            ->all();

        if ($unknown !== []) {
            throw new RuntimeException('Unknown Employee role values; migration aborted: '.implode(', ', $unknown));
        }
    }

    /** @param array<string, string> $map */
    private function mapValues(array $map): void
    {
        foreach ($map as $from => $to) {
            if ($from !== $to) {
                DB::table('employees')->where('role', $from)->update(['role' => $to]);
            }
        }
    }

    /** @param array<string, string> $map */
    private function rebuildSqliteTable(array $map, string $default): void
    {
        $connection = DB::connection();
        $foreignKeysEnabled = (bool) $connection->scalar('PRAGMA foreign_keys');

        $connection->statement('PRAGMA foreign_keys = OFF');

        try {
            $connection->transaction(function () use ($connection, $map, $default): void {
                $connection->statement($this->sqliteCreateTableSql($default));

                $roleCase = collect($map)
                    ->map(fn (string $to, string $from): string => "WHEN role = '".$this->quote($from)."' THEN '".$this->quote($to)."'")
                    ->implode(' ');

                $connection->statement(<<<SQL
                    INSERT INTO employees_role_normalization_tmp
                        (id, user_id, employee_id, name, email, password, phone, team_id, designation, role, status, joining_date, created_at, updated_at)
                    SELECT id, user_id, employee_id, name, email, password, phone, team_id, designation,
                        CASE {$roleCase} ELSE role END,
                        status, joining_date, created_at, updated_at
                    FROM employees
                    SQL);

                $connection->statement('DROP TABLE employees');
                $connection->statement('ALTER TABLE employees_role_normalization_tmp RENAME TO employees');
                $connection->statement('CREATE UNIQUE INDEX employees_user_id_unique ON employees (user_id)');
                $connection->statement('CREATE UNIQUE INDEX employees_employee_id_unique ON employees (employee_id)');
                $connection->statement('CREATE UNIQUE INDEX employees_email_unique ON employees (email)');
            });
        } finally {
            if ($foreignKeysEnabled) {
                $connection->statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    private function sqliteCreateTableSql(string $default): string
    {
        $values = $default === 'Staff'
            ? "'Owner', 'Admin', 'Manager', 'Staff'"
            : "'owner', 'admin', 'manager', 'staff'";

        return <<<SQL
            CREATE TABLE employees_role_normalization_tmp (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NULL,
                employee_id VARCHAR NOT NULL,
                name VARCHAR NOT NULL,
                email VARCHAR NOT NULL,
                password VARCHAR NULL,
                phone VARCHAR NULL,
                team_id INTEGER NULL,
                designation VARCHAR NOT NULL,
                role VARCHAR NOT NULL DEFAULT '{$default}' CHECK (role IN ({$values})),
                status TINYINT(1) NOT NULL DEFAULT '1',
                joining_date DATE NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
                FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
            )
            SQL;
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
};
