<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLES = ['owner', 'admin', 'manager', 'staff', 'technician'];

    private const PREVIOUS_ROLES = ['owner', 'admin', 'manager', 'staff'];

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE employees MODIFY role ENUM('owner','admin','manager','staff','technician') NOT NULL DEFAULT 'staff'");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('employees', function (Blueprint $table): void {
                $table->enum('role', self::ROLES)->default('staff')->change();
            });

            return;
        }

        throw new RuntimeException('Adding the Technician role supports only the approved MySQL and SQLite databases.');
    }

    public function down(): void
    {
        if (DB::table('employees')->where('role', 'technician')->exists()) {
            throw new RuntimeException('Cannot remove the Technician role while Technician employees still exist. Reassign them first.');
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE employees MODIFY role ENUM('owner','admin','manager','staff') NOT NULL DEFAULT 'staff'");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('employees', function (Blueprint $table): void {
                $table->enum('role', self::PREVIOUS_ROLES)->default('staff')->change();
            });

            return;
        }

        throw new RuntimeException('Removing the Technician role supports only the approved MySQL and SQLite databases.');
    }
};
