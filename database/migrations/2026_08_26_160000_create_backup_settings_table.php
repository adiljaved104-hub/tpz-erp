<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE backup_settings (
 id INTEGER PRIMARY KEY CHECK (id = 1),
 enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
 backup_time VARCHAR(5) NOT NULL DEFAULT '02:00' CHECK (
   backup_time GLOB '[0-2][0-9]:[0-5][0-9]'
   AND CAST(SUBSTR(backup_time, 1, 2) AS INTEGER) BETWEEN 0 AND 23
 ),
 database_enabled INTEGER NOT NULL DEFAULT 1 CHECK (database_enabled IN (0, 1)),
 storage_enabled INTEGER NOT NULL DEFAULT 1 CHECK (storage_enabled IN (0, 1)),
 daily_retention INTEGER NOT NULL DEFAULT 14 CHECK (daily_retention BETWEEN 1 AND 365),
 weekly_retention INTEGER NOT NULL DEFAULT 8 CHECK (weekly_retention BETWEEN 0 AND 104),
 monthly_retention INTEGER NOT NULL DEFAULT 6 CHECK (monthly_retention BETWEEN 0 AND 60),
 backup_disk VARCHAR(64) NOT NULL DEFAULT 'local' CHECK (LENGTH(TRIM(backup_disk)) BETWEEN 1 AND 64),
 offsite_enabled INTEGER NOT NULL DEFAULT 0 CHECK (offsite_enabled IN (0, 1)),
 encryption_enabled INTEGER NOT NULL DEFAULT 0 CHECK (encryption_enabled IN (0, 1)),
 updated_by_user_id INTEGER NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK (enabled = 0 OR database_enabled = 1 OR storage_enabled = 1)
)
SQL);

            return;
        }

        Schema::create('backup_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('backup_time', 5)->default('02:00');
            $table->boolean('database_enabled')->default(true);
            $table->boolean('storage_enabled')->default(true);
            $table->unsignedSmallInteger('daily_retention')->default(14);
            $table->unsignedSmallInteger('weekly_retention')->default(8);
            $table->unsignedSmallInteger('monthly_retention')->default(6);
            $table->string('backup_disk', 64)->default('local');
            $table->boolean('offsite_enabled')->default(false);
            $table->boolean('encryption_enabled')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE backup_settings ADD CONSTRAINT backup_settings_values_check CHECK (
 id = 1
 AND enabled IN (0, 1)
 AND database_enabled IN (0, 1)
 AND storage_enabled IN (0, 1)
 AND offsite_enabled IN (0, 1)
 AND encryption_enabled IN (0, 1)
 AND backup_time REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$'
 AND daily_retention BETWEEN 1 AND 365
 AND weekly_retention BETWEEN 0 AND 104
 AND monthly_retention BETWEEN 0 AND 60
 AND CHAR_LENGTH(TRIM(backup_disk)) BETWEEN 1 AND 64
 AND (enabled = 0 OR database_enabled = 1 OR storage_enabled = 1)
)
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('backup_settings') && DB::table('backup_settings')->exists()) {
            throw new RuntimeException('Rollback refused: Backup Settings have been configured.');
        }

        Schema::dropIfExists('backup_settings');
    }
};
