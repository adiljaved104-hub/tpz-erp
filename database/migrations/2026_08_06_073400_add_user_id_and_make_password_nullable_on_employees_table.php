<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(includeUserId: true, passwordNullable: true);

            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('employees')->whereNotNull('user_id')->exists()) {
            throw new RuntimeException('Cannot roll back while Employees are linked to Users. Unlink them through an approved recovery procedure first.');
        }

        if (DB::table('employees')->whereNull('password')->exists()) {
            throw new RuntimeException('Cannot restore a non-null Employee password column while null legacy values exist.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(includeUserId: false, passwordNullable: false);

            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->string('password')->nullable(false)->change();
        });
    }

    private function rebuildSqliteTable(bool $includeUserId, bool $passwordNullable): void
    {
        $connection = DB::connection();
        $foreignKeysEnabled = (bool) $connection->scalar('PRAGMA foreign_keys');
        $connection->statement('PRAGMA foreign_keys = OFF');

        try {
            $connection->transaction(function () use ($connection, $includeUserId, $passwordNullable): void {
                $userColumn = $includeUserId ? 'user_id INTEGER NULL,' : '';
                $userForeignKey = $includeUserId ? ', FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL' : '';
                $passwordNull = $passwordNullable ? 'NULL' : 'NOT NULL';

                $connection->statement(<<<SQL
                    CREATE TABLE employees_user_alignment_tmp (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        {$userColumn}
                        employee_id VARCHAR NOT NULL,
                        name VARCHAR NOT NULL,
                        email VARCHAR NOT NULL,
                        password VARCHAR {$passwordNull},
                        phone VARCHAR NULL,
                        team_id INTEGER NULL,
                        designation VARCHAR NOT NULL,
                        role VARCHAR NOT NULL DEFAULT 'Staff' CHECK (role IN ('Owner', 'Admin', 'Manager', 'Staff')),
                        status TINYINT(1) NOT NULL DEFAULT '1',
                        joining_date DATE NULL,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
                        {$userForeignKey}
                    )
                    SQL);

                $targetColumns = $includeUserId
                    ? 'id, user_id, employee_id, name, email, password, phone, team_id, designation, role, status, joining_date, created_at, updated_at'
                    : 'id, employee_id, name, email, password, phone, team_id, designation, role, status, joining_date, created_at, updated_at';
                $sourceColumns = $includeUserId
                    ? 'id, NULL, employee_id, name, email, password, phone, team_id, designation, role, status, joining_date, created_at, updated_at'
                    : 'id, employee_id, name, email, password, phone, team_id, designation, role, status, joining_date, created_at, updated_at';

                $connection->statement("INSERT INTO employees_user_alignment_tmp ({$targetColumns}) SELECT {$sourceColumns} FROM employees");
                $connection->statement('DROP TABLE employees');
                $connection->statement('ALTER TABLE employees_user_alignment_tmp RENAME TO employees');
                $connection->statement('CREATE UNIQUE INDEX employees_employee_id_unique ON employees (employee_id)');
                $connection->statement('CREATE UNIQUE INDEX employees_email_unique ON employees (email)');

                if ($includeUserId) {
                    $connection->statement('CREATE UNIQUE INDEX employees_user_id_unique ON employees (user_id)');
                }
            });
        } finally {
            if ($foreignKeysEnabled) {
                $connection->statement('PRAGMA foreign_keys = ON');
            }
        }
    }
};
