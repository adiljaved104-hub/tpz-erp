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
            $this->createSqliteTable();

            return;
        }

        Schema::create('biometric_attendance_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 50);
            $table->string('device_identifier', 150);
            $table->string('mode', 20);
            $table->timestamp('window_from');
            $table->timestamp('window_to');
            $table->string('status', 20);
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('unmapped_count')->default(0);
            // Sanitized operational diagnostics only; never credentials, authorization data, or request headers.
            $table->string('safe_error_code', 100)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(
                ['source', 'device_identifier', 'status', 'completed_at'],
                'biometric_sync_runs_source_device_status_index',
            );
            $table->index(
                ['source', 'device_identifier', 'window_from', 'window_to'],
                'biometric_sync_runs_source_device_window_index',
            );
            $table->index(['status', 'attempted_at'], 'biometric_sync_runs_status_attempted_index');
        });

        DB::statement("ALTER TABLE biometric_attendance_sync_runs ADD CONSTRAINT biometric_sync_runs_values_check CHECK (CHAR_LENGTH(TRIM(source)) > 0 AND CHAR_LENGTH(TRIM(device_identifier)) > 0 AND mode IN ('manual','incremental','backfill') AND status IN ('running','successful','partial','failed') AND window_from <= window_to AND imported_count >= 0 AND duplicate_count >= 0 AND skipped_count >= 0 AND unmapped_count >= 0)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('biometric_attendance_sync_runs') && DB::table('biometric_attendance_sync_runs')->exists()) {
            throw new RuntimeException('Rollback refused: biometric_attendance_sync_runs contains durable synchronization history.');
        }

        Schema::dropIfExists('biometric_attendance_sync_runs');
    }

    /**
     * The incremental cursor is derived from the latest successful run for a
     * source/device. Partial and failed runs never advance that cursor.
     */
    private function createSqliteTable(): void
    {
        DB::statement("CREATE TABLE biometric_attendance_sync_runs (id INTEGER PRIMARY KEY AUTOINCREMENT,source VARCHAR(50) NOT NULL,device_identifier VARCHAR(150) NOT NULL,mode VARCHAR(20) NOT NULL,window_from DATETIME NOT NULL,window_to DATETIME NOT NULL,status VARCHAR(20) NOT NULL,attempted_at DATETIME NOT NULL,completed_at DATETIME NULL,initiated_by_user_id INTEGER NULL,imported_count INTEGER NOT NULL DEFAULT 0,duplicate_count INTEGER NOT NULL DEFAULT 0,skipped_count INTEGER NOT NULL DEFAULT 0,unmapped_count INTEGER NOT NULL DEFAULT 0,safe_error_code VARCHAR(100) NULL,safe_error_message TEXT NULL,idempotency_key VARCHAR(36) NOT NULL UNIQUE,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(length(trim(source)) > 0),CHECK(length(trim(device_identifier)) > 0),CHECK(mode IN ('manual','incremental','backfill')),CHECK(status IN ('running','successful','partial','failed')),CHECK(window_from <= window_to),CHECK(imported_count >= 0),CHECK(duplicate_count >= 0),CHECK(skipped_count >= 0),CHECK(unmapped_count >= 0),FOREIGN KEY(initiated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX biometric_sync_runs_source_device_status_index ON biometric_attendance_sync_runs(source,device_identifier,status,completed_at)');
        DB::statement('CREATE INDEX biometric_sync_runs_source_device_window_index ON biometric_attendance_sync_runs(source,device_identifier,window_from,window_to)');
        DB::statement('CREATE INDEX biometric_sync_runs_status_attempted_index ON biometric_attendance_sync_runs(status,attempted_at)');
    }
};
