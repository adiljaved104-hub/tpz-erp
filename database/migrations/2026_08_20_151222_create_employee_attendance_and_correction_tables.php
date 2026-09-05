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

        Schema::create('employee_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('attendance_date');
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->restrictOnDelete();
            $table->foreignId('attendance_policy_id')->nullable()->constrained('attendance_policies')->restrictOnDelete();
            $table->timestamp('expected_start_at')->nullable();
            $table->timestamp('expected_end_at')->nullable();
            $table->timestamp('first_check_in_at')->nullable();
            $table->timestamp('last_check_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_departure_minutes')->default(0);
            $table->string('source', 30);
            $table->boolean('is_overridden')->default(false);
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
            $table->index(['employee_id', 'status', 'attendance_date'], 'employee_attendance_status_date_index');
        });
        DB::statement("ALTER TABLE employee_attendances ADD CONSTRAINT employee_attendances_values_check CHECK (status IN ('present','late','absent','half_day','approved_leave','unpaid_leave','public_holiday','weekend_off','compensatory_off') AND source IN ('biometric','manual','imported','system_derived') AND (last_check_out_at IS NULL OR first_check_in_at IS NOT NULL) AND (last_check_out_at IS NULL OR last_check_out_at >= first_check_in_at))");

        Schema::create('attendance_evidence_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_attendance_id')->constrained('employee_attendances')->restrictOnDelete();
            $table->foreignId('biometric_attendance_event_id')->constrained('biometric_attendance_events')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['employee_attendance_id', 'biometric_attendance_event_id'], 'attendance_evidence_unique');
            $table->unique('biometric_attendance_event_id', 'attendance_evidence_event_unique');
        });

        Schema::create('attendance_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_attendance_id')->constrained('employee_attendances')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('old_values');
            $table->json('new_values');
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['employee_attendance_id', 'occurred_at'], 'attendance_corrections_attendance_time_index');
            $table->index(['actor_user_id', 'occurred_at']);
        });
        DB::statement('ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_reason_check CHECK (CHAR_LENGTH(TRIM(reason)) > 0)');
    }

    public function down(): void
    {
        foreach (['attendance_corrections', 'attendance_evidence_links', 'employee_attendances'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains permanent Attendance history.");
            }
        }
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_evidence_links');
        Schema::dropIfExists('employee_attendances');
    }

    private function createSqliteTables(): void
    {
        DB::statement("CREATE TABLE employee_attendances (id INTEGER PRIMARY KEY AUTOINCREMENT,employee_id INTEGER NOT NULL,attendance_date DATE NOT NULL,work_schedule_id INTEGER NULL,attendance_policy_id INTEGER NULL,expected_start_at DATETIME NULL,expected_end_at DATETIME NULL,first_check_in_at DATETIME NULL,last_check_out_at DATETIME NULL,worked_minutes INTEGER NULL CHECK(worked_minutes IS NULL OR worked_minutes >= 0),status VARCHAR(30) NOT NULL CHECK(status IN ('present','late','absent','half_day','approved_leave','unpaid_leave','public_holiday','weekend_off','compensatory_off')),late_minutes INTEGER NOT NULL DEFAULT 0 CHECK(late_minutes >= 0),early_departure_minutes INTEGER NOT NULL DEFAULT 0 CHECK(early_departure_minutes >= 0),source VARCHAR(30) NOT NULL CHECK(source IN ('biometric','manual','imported','system_derived')),is_overridden TINYINT(1) NOT NULL DEFAULT 0,calculated_at DATETIME NOT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(employee_id,attendance_date),CHECK(last_check_out_at IS NULL OR first_check_in_at IS NOT NULL),CHECK(last_check_out_at IS NULL OR last_check_out_at >= first_check_in_at),FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(work_schedule_id) REFERENCES work_schedules(id) ON DELETE RESTRICT,FOREIGN KEY(attendance_policy_id) REFERENCES attendance_policies(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX employee_attendances_attendance_date_status_index ON employee_attendances(attendance_date,status)');
        DB::statement('CREATE INDEX employee_attendance_status_date_index ON employee_attendances(employee_id,status,attendance_date)');
        DB::statement('CREATE TABLE attendance_evidence_links (id INTEGER PRIMARY KEY AUTOINCREMENT,employee_attendance_id INTEGER NOT NULL,biometric_attendance_event_id INTEGER NOT NULL,created_at DATETIME NULL,UNIQUE(employee_attendance_id,biometric_attendance_event_id),UNIQUE(biometric_attendance_event_id),FOREIGN KEY(employee_attendance_id) REFERENCES employee_attendances(id) ON DELETE RESTRICT,FOREIGN KEY(biometric_attendance_event_id) REFERENCES biometric_attendance_events(id) ON DELETE RESTRICT)');
        DB::statement('CREATE TABLE attendance_corrections (id INTEGER PRIMARY KEY AUTOINCREMENT,employee_attendance_id INTEGER NOT NULL,actor_user_id INTEGER NOT NULL,reason TEXT NOT NULL CHECK(length(trim(reason)) > 0),old_values TEXT NOT NULL,new_values TEXT NOT NULL,idempotency_key VARCHAR(36) NOT NULL UNIQUE,occurred_at DATETIME NOT NULL,created_at DATETIME NULL,FOREIGN KEY(employee_attendance_id) REFERENCES employee_attendances(id) ON DELETE RESTRICT,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX attendance_corrections_attendance_time_index ON attendance_corrections(employee_attendance_id,occurred_at)');
        DB::statement('CREATE INDEX attendance_corrections_actor_user_id_occurred_at_index ON attendance_corrections(actor_user_id,occurred_at)');
    }
};
