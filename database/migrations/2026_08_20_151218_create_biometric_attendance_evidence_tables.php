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
            $this->createSqliteTables();

            return;
        }

        Schema::create('biometric_employee_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('source', 50);
            $table->string('external_employee_identifier', 150);
            $table->boolean('status')->default(true)->index();
            $table->string('active_fingerprint')->nullable()->unique();
            $table->foreignId('mapped_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->unique(['source', 'external_employee_identifier'], 'biometric_identifier_source_external_unique');
            $table->index(['employee_id', 'source', 'status'], 'biometric_identifier_employee_source_index');
        });
        DB::statement('ALTER TABLE biometric_employee_identifiers ADD CONSTRAINT biometric_identifiers_values_check CHECK (CHAR_LENGTH(TRIM(source)) > 0 AND CHAR_LENGTH(TRIM(external_employee_identifier)) > 0 AND CHAR_LENGTH(TRIM(reason)) > 0 AND ((status = 1 AND active_fingerprint IS NOT NULL) OR (status = 0 AND active_fingerprint IS NULL)))');

        Schema::create('biometric_attendance_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 50);
            $table->string('source_event_id', 190)->nullable();
            $table->string('external_employee_identifier', 150);
            $table->string('device_identifier', 150)->nullable();
            $table->timestamp('punched_at');
            $table->string('punch_type', 30)->nullable();
            $table->json('raw_metadata')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('imported_at');
            $table->timestamp('created_at')->nullable();
            $table->unique(['source', 'device_identifier', 'source_event_id'], 'biometric_events_external_unique');
            $table->index(['source', 'external_employee_identifier', 'punched_at'], 'biometric_events_employee_time_index');
            $table->index(['punched_at', 'source']);
        });
        DB::statement("ALTER TABLE biometric_attendance_events ADD CONSTRAINT biometric_events_values_check CHECK (CHAR_LENGTH(TRIM(source)) > 0 AND CHAR_LENGTH(TRIM(external_employee_identifier)) > 0 AND (punch_type IS NULL OR punch_type IN ('check_in','check_out','break_out','break_in','unknown')))");

        Schema::create('biometric_event_employee_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('biometric_attendance_event_id');
            $table->foreign('biometric_attendance_event_id', 'biometric_mappings_event_fk')->references('id')->on('biometric_attendance_events')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('biometric_employee_identifier_id')->nullable();
            $table->foreign('biometric_employee_identifier_id', 'biometric_mappings_identifier_fk')->references('id')->on('biometric_employee_identifiers')->restrictOnDelete();
            $table->foreignId('mapped_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('mapped_at');
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('active_fingerprint')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('created_at')->nullable();
            $table->index(['employee_id', 'mapped_at']);
            $table->index(['biometric_attendance_event_id', 'mapped_at'], 'biometric_event_mappings_event_time_index');
        });
        DB::statement('ALTER TABLE biometric_event_employee_mappings ADD CONSTRAINT biometric_event_mappings_values_check CHECK (CHAR_LENGTH(TRIM(reason)) > 0 AND ((superseded_at IS NULL AND superseded_by_user_id IS NULL AND active_fingerprint IS NOT NULL) OR (superseded_at IS NOT NULL AND superseded_by_user_id IS NOT NULL AND active_fingerprint IS NULL)))');
    }

    public function down(): void
    {
        foreach (['biometric_event_employee_mappings', 'biometric_attendance_events', 'biometric_employee_identifiers'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains immutable biometric attendance evidence or mapping history.");
            }
        }
        Schema::dropIfExists('biometric_event_employee_mappings');
        Schema::dropIfExists('biometric_attendance_events');
        Schema::dropIfExists('biometric_employee_identifiers');
    }

    private function createSqliteTables(): void
    {
        DB::statement('CREATE TABLE biometric_employee_identifiers (id INTEGER PRIMARY KEY AUTOINCREMENT,employee_id INTEGER NOT NULL,source VARCHAR(50) NOT NULL,external_employee_identifier VARCHAR(150) NOT NULL,status TINYINT(1) NOT NULL DEFAULT 1,active_fingerprint VARCHAR(255) NULL UNIQUE,mapped_by_user_id INTEGER NOT NULL,reason TEXT NOT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(source,external_employee_identifier),CHECK(length(trim(source)) > 0 AND length(trim(external_employee_identifier)) > 0 AND length(trim(reason)) > 0),CHECK((status = 1 AND active_fingerprint IS NOT NULL) OR (status = 0 AND active_fingerprint IS NULL)),FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(mapped_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX biometric_identifiers_status_index ON biometric_employee_identifiers(status)');
        DB::statement('CREATE INDEX biometric_identifier_employee_source_index ON biometric_employee_identifiers(employee_id,source,status)');
        DB::statement("CREATE TABLE biometric_attendance_events (id INTEGER PRIMARY KEY AUTOINCREMENT,source VARCHAR(50) NOT NULL,source_event_id VARCHAR(190) NULL,external_employee_identifier VARCHAR(150) NOT NULL,device_identifier VARCHAR(150) NULL,punched_at DATETIME NOT NULL,punch_type VARCHAR(30) NULL CHECK(punch_type IS NULL OR punch_type IN ('check_in','check_out','break_out','break_in','unknown')),raw_metadata TEXT NULL,idempotency_key VARCHAR(36) NOT NULL UNIQUE,imported_at DATETIME NOT NULL,created_at DATETIME NULL,CHECK(length(trim(source)) > 0 AND length(trim(external_employee_identifier)) > 0),UNIQUE(source,device_identifier,source_event_id))");
        DB::statement('CREATE INDEX biometric_events_employee_time_index ON biometric_attendance_events(source,external_employee_identifier,punched_at)');
        DB::statement('CREATE INDEX biometric_attendance_events_punched_at_source_index ON biometric_attendance_events(punched_at,source)');
        DB::statement('CREATE TABLE biometric_event_employee_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT,biometric_attendance_event_id INTEGER NOT NULL,employee_id INTEGER NOT NULL,biometric_employee_identifier_id INTEGER NULL,mapped_by_user_id INTEGER NOT NULL,reason TEXT NOT NULL CHECK(length(trim(reason)) > 0),mapped_at DATETIME NOT NULL,superseded_at DATETIME NULL,superseded_by_user_id INTEGER NULL,active_fingerprint VARCHAR(255) NULL UNIQUE,idempotency_key VARCHAR(36) NOT NULL UNIQUE,created_at DATETIME NULL,CHECK((superseded_at IS NULL AND superseded_by_user_id IS NULL AND active_fingerprint IS NOT NULL) OR (superseded_at IS NOT NULL AND superseded_by_user_id IS NOT NULL AND active_fingerprint IS NULL)),FOREIGN KEY(biometric_attendance_event_id) REFERENCES biometric_attendance_events(id) ON DELETE RESTRICT,FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(biometric_employee_identifier_id) REFERENCES biometric_employee_identifiers(id) ON DELETE RESTRICT,FOREIGN KEY(mapped_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,FOREIGN KEY(superseded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX biometric_event_employee_mappings_employee_id_mapped_at_index ON biometric_event_employee_mappings(employee_id,mapped_at)');
        DB::statement('CREATE INDEX biometric_event_mappings_event_time_index ON biometric_event_employee_mappings(biometric_attendance_event_id,mapped_at)');
    }
};
