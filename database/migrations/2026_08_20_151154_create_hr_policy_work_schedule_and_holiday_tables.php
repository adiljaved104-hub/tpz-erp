<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ANNUAL_ENTITLEMENT_KEY = 'annual_leave_entitlement_days';

    private const DEFAULT_EFFECTIVE_FROM = '2026-01-01';

    public function up(): void
    {
        DB::getDriverName() === 'sqlite' ? $this->createSqliteTables() : $this->createMysqlTables();

        DB::table('hr_settings')->insert([
            'key' => self::ANNUAL_ENTITLEMENT_KEY, 'value' => '12.00', 'value_type' => 'decimal',
            'description' => 'Default paid Annual Leave entitlement in days per leave year.',
            'updated_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = now();
        $attendancePolicyId = DB::table('attendance_policies')->insertGetId([
            'name' => 'Pakistan Office Attendance Policy',
            'office_start_time' => '09:00:00',
            'office_end_time' => '17:00:00',
            'grace_minutes' => 5,
            'late_after_minutes' => 6,
            'late_occurrences_for_penalty' => 3,
            'absence_equivalent_penalty_days' => '1.00',
            'half_day_minimum_minutes' => null,
            'full_day_minimum_minutes' => null,
            'absence_rule' => 'no_qualifying_punch',
            'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
            'effective_to' => null,
            'status' => true,
            'created_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('work_schedules')->insert([
            'code' => 'PK_OFFICE',
            'name' => 'Pakistan Office',
            'timezone' => 'Asia/Karachi',
            'schedule_type' => 'standard',
            'cycle_length_weeks' => 1,
            'attendance_policy_id' => $attendancePolicyId,
            'status' => true,
            'created_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('leave_policies')->insert([
            'name' => 'Pakistan Calendar-Year Leave Policy',
            'annual_leave_entitlement_days' => '12.00',
            'leave_year_mode' => 'calendar_year',
            'half_day_leave_enabled' => false,
            'manager_approval_enabled' => false,
            'compensatory_off_requires_approval' => true,
            'compensatory_off_expiry_days' => null,
            'scheduled_sunday_compensatory_off_enabled' => true,
            'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
            'effective_to' => null,
            'status' => true,
            'created_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        foreach (['public_holidays', 'work_schedule_assignments', 'work_schedule_days'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains HR configuration or history.");
            }
        }
        $this->assertSeededConfigurationIsUnchanged();
        if (Schema::hasTable('hr_settings')) {
            $settings = DB::table('hr_settings')->get();
            if ($settings->count() !== 1 || $settings->first()->key !== self::ANNUAL_ENTITLEMENT_KEY || (string) $settings->first()->value !== '12.00') {
                throw new RuntimeException('Rollback refused: HR settings have been changed.');
            }
            DB::table('hr_settings')->where('key', self::ANNUAL_ENTITLEMENT_KEY)->delete();
        }
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('work_schedule_assignments');
        Schema::dropIfExists('work_schedule_days');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('attendance_policies');
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('hr_settings');
    }

    private function createMysqlTables(): void
    {
        Schema::create('hr_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->string('value_type', 20);
            $table->string('description')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE hr_settings ADD CONSTRAINT hr_settings_type_check CHECK (value_type IN ('integer','decimal','boolean','string','json'))");
        Schema::create('attendance_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->time('office_start_time');
            $table->time('office_end_time');
            $table->unsignedInteger('grace_minutes')->default(0);
            $table->unsignedInteger('late_after_minutes')->default(0);
            $table->unsignedInteger('late_occurrences_for_penalty')->default(3);
            $table->decimal('absence_equivalent_penalty_days', 4, 2)->default(1);
            $table->unsignedInteger('half_day_minimum_minutes')->nullable();
            $table->unsignedInteger('full_day_minimum_minutes')->nullable();
            $table->string('absence_rule', 40)->default('no_qualifying_punch');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['effective_from', 'effective_to'], 'attendance_policies_effective_index');
        });
        DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_minutes_check CHECK (half_day_minimum_minutes IS NULL OR full_day_minimum_minutes IS NULL OR full_day_minimum_minutes >= half_day_minimum_minutes)');
        DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_times_check CHECK (office_end_time > office_start_time AND late_after_minutes > grace_minutes AND late_occurrences_for_penalty > 0 AND absence_equivalent_penalty_days > 0)');
        DB::statement("ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_absence_rule_check CHECK (absence_rule IN ('no_qualifying_punch','below_minimum_minutes','manual_review'))");
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('timezone', 64);
            $table->string('schedule_type', 30)->default('standard');
            $table->unsignedTinyInteger('cycle_length_weeks')->default(1);
            $table->foreignId('attendance_policy_id')->nullable()->constrained('attendance_policies')->restrictOnDelete();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE work_schedules ADD CONSTRAINT work_schedules_values_check CHECK (schedule_type IN ('standard','alternative_weekend','custom') AND cycle_length_weeks BETWEEN 1 AND 12)");
        Schema::create('leave_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('annual_leave_entitlement_days', 6, 2);
            $table->string('leave_year_mode', 30)->default('calendar_year');
            $table->boolean('half_day_leave_enabled')->default(false);
            $table->boolean('manager_approval_enabled')->default(false);
            $table->boolean('compensatory_off_requires_approval')->default(true);
            $table->unsignedInteger('compensatory_off_expiry_days')->nullable();
            $table->boolean('scheduled_sunday_compensatory_off_enabled')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['effective_from', 'effective_to'], 'leave_policies_effective_index');
        });
        DB::statement("ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_values_check CHECK (annual_leave_entitlement_days >= 0 AND leave_year_mode IN ('calendar_year') AND (effective_to IS NULL OR effective_to >= effective_from))");
        Schema::create('work_schedule_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->restrictOnDelete();
            $table->unsignedTinyInteger('cycle_week')->default(1);
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_working_day')->default(true);
            $table->time('expected_start_time')->nullable();
            $table->time('expected_end_time')->nullable();
            $table->time('break_start_time')->nullable();
            $table->time('break_end_time')->nullable();
            $table->boolean('earns_compensatory_off')->default(false);
            $table->timestamps();
            $table->unique(['work_schedule_id', 'cycle_week', 'weekday'], 'work_schedule_days_unique');
        });
        DB::statement('ALTER TABLE work_schedule_days ADD CONSTRAINT work_schedule_days_values_check CHECK (cycle_week BETWEEN 1 AND 12 AND weekday BETWEEN 0 AND 6 AND ((is_working_day = 1 AND expected_start_time IS NOT NULL AND expected_end_time IS NOT NULL) OR (is_working_day = 0 AND expected_start_time IS NULL AND expected_end_time IS NULL AND earns_compensatory_off = 0)) AND ((break_start_time IS NULL AND break_end_time IS NULL) OR (break_start_time IS NOT NULL AND break_end_time IS NOT NULL)))');
        Schema::create('work_schedule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from'], 'work_schedule_employee_effective_unique');
            $table->unique(['team_id', 'effective_from'], 'work_schedule_team_effective_unique');
            $table->index(['employee_id', 'effective_from', 'effective_to'], 'work_schedule_employee_lookup_index');
            $table->index(['team_id', 'effective_from', 'effective_to'], 'work_schedule_team_lookup_index');
        });
        DB::statement('ALTER TABLE work_schedule_assignments ADD CONSTRAINT work_schedule_assignments_values_check CHECK (((employee_id IS NOT NULL AND team_id IS NULL) OR (employee_id IS NULL AND team_id IS NOT NULL)) AND (effective_to IS NULL OR effective_to >= effective_from) AND CHAR_LENGTH(TRIM(reason)) > 0)');
        Schema::create('public_holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('holiday_date')->unique();
            $table->string('name');
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    private function createSqliteTables(): void
    {
        DB::statement("CREATE TABLE hr_settings (key VARCHAR(100) PRIMARY KEY NOT NULL,value TEXT NOT NULL,value_type VARCHAR(20) NOT NULL CHECK(value_type IN ('integer','decimal','boolean','string','json')),description VARCHAR(255) NULL,updated_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement("CREATE TABLE attendance_policies (id INTEGER PRIMARY KEY AUTOINCREMENT,name VARCHAR(255) NOT NULL,office_start_time TIME NOT NULL,office_end_time TIME NOT NULL,grace_minutes INTEGER NOT NULL DEFAULT 0 CHECK(grace_minutes >= 0),late_after_minutes INTEGER NOT NULL DEFAULT 0 CHECK(late_after_minutes >= 0),late_occurrences_for_penalty INTEGER NOT NULL DEFAULT 3 CHECK(late_occurrences_for_penalty > 0),absence_equivalent_penalty_days DECIMAL(4,2) NOT NULL DEFAULT 1 CHECK(absence_equivalent_penalty_days > 0),half_day_minimum_minutes INTEGER NULL CHECK(half_day_minimum_minutes IS NULL OR half_day_minimum_minutes >= 0),full_day_minimum_minutes INTEGER NULL CHECK(full_day_minimum_minutes IS NULL OR full_day_minimum_minutes >= 0),absence_rule VARCHAR(40) NOT NULL DEFAULT 'no_qualifying_punch' CHECK(absence_rule IN ('no_qualifying_punch','below_minimum_minutes','manual_review')),effective_from DATE NOT NULL,effective_to DATE NULL,status TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(effective_to IS NULL OR effective_to >= effective_from),CHECK(office_end_time > office_start_time),CHECK(late_after_minutes > grace_minutes),CHECK(half_day_minimum_minutes IS NULL OR full_day_minimum_minutes IS NULL OR full_day_minimum_minutes >= half_day_minimum_minutes),FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX attendance_policies_status_index ON attendance_policies(status)');
        DB::statement('CREATE INDEX attendance_policies_effective_index ON attendance_policies(effective_from,effective_to)');
        DB::statement("CREATE TABLE work_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT,code VARCHAR(50) NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,timezone VARCHAR(64) NOT NULL,schedule_type VARCHAR(30) NOT NULL DEFAULT 'standard' CHECK(schedule_type IN ('standard','alternative_weekend','custom')),cycle_length_weeks INTEGER NOT NULL DEFAULT 1 CHECK(cycle_length_weeks BETWEEN 1 AND 12),attendance_policy_id INTEGER NULL,status TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,FOREIGN KEY(attendance_policy_id) REFERENCES attendance_policies(id) ON DELETE RESTRICT,FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX work_schedules_status_index ON work_schedules(status)');
        DB::statement("CREATE TABLE leave_policies (id INTEGER PRIMARY KEY AUTOINCREMENT,name VARCHAR(255) NOT NULL,annual_leave_entitlement_days DECIMAL(6,2) NOT NULL CHECK(annual_leave_entitlement_days >= 0),leave_year_mode VARCHAR(30) NOT NULL DEFAULT 'calendar_year' CHECK(leave_year_mode IN ('calendar_year')),half_day_leave_enabled TINYINT(1) NOT NULL DEFAULT 0,manager_approval_enabled TINYINT(1) NOT NULL DEFAULT 0,compensatory_off_requires_approval TINYINT(1) NOT NULL DEFAULT 1,compensatory_off_expiry_days INTEGER NULL CHECK(compensatory_off_expiry_days IS NULL OR compensatory_off_expiry_days >= 0),scheduled_sunday_compensatory_off_enabled TINYINT(1) NOT NULL DEFAULT 1,effective_from DATE NOT NULL,effective_to DATE NULL,status TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(effective_to IS NULL OR effective_to >= effective_from),FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX leave_policies_status_index ON leave_policies(status)');
        DB::statement('CREATE INDEX leave_policies_effective_index ON leave_policies(effective_from,effective_to)');
        DB::statement('CREATE TABLE work_schedule_days (id INTEGER PRIMARY KEY AUTOINCREMENT,work_schedule_id INTEGER NOT NULL,cycle_week INTEGER NOT NULL DEFAULT 1 CHECK(cycle_week BETWEEN 1 AND 12),weekday INTEGER NOT NULL CHECK(weekday BETWEEN 0 AND 6),is_working_day TINYINT(1) NOT NULL DEFAULT 1,expected_start_time TIME NULL,expected_end_time TIME NULL,break_start_time TIME NULL,break_end_time TIME NULL,earns_compensatory_off TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(work_schedule_id,cycle_week,weekday),CHECK((is_working_day = 1 AND expected_start_time IS NOT NULL AND expected_end_time IS NOT NULL) OR (is_working_day = 0 AND expected_start_time IS NULL AND expected_end_time IS NULL AND earns_compensatory_off = 0)),CHECK((break_start_time IS NULL AND break_end_time IS NULL) OR (break_start_time IS NOT NULL AND break_end_time IS NOT NULL)),FOREIGN KEY(work_schedule_id) REFERENCES work_schedules(id) ON DELETE RESTRICT)');
        DB::statement('CREATE TABLE work_schedule_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT,work_schedule_id INTEGER NOT NULL,employee_id INTEGER NULL,team_id INTEGER NULL,effective_from DATE NOT NULL,effective_to DATE NULL,assigned_by_user_id INTEGER NOT NULL,reason TEXT NOT NULL CHECK(length(trim(reason)) > 0),created_at DATETIME NULL,updated_at DATETIME NULL,CHECK((employee_id IS NOT NULL AND team_id IS NULL) OR (employee_id IS NULL AND team_id IS NOT NULL)),CHECK(effective_to IS NULL OR effective_to >= effective_from),FOREIGN KEY(work_schedule_id) REFERENCES work_schedules(id) ON DELETE RESTRICT,FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE RESTRICT,FOREIGN KEY(assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE UNIQUE INDEX work_schedule_employee_effective_unique ON work_schedule_assignments(employee_id,effective_from)');
        DB::statement('CREATE UNIQUE INDEX work_schedule_team_effective_unique ON work_schedule_assignments(team_id,effective_from)');
        DB::statement('CREATE INDEX work_schedule_employee_lookup_index ON work_schedule_assignments(employee_id,effective_from,effective_to)');
        DB::statement('CREATE INDEX work_schedule_team_lookup_index ON work_schedule_assignments(team_id,effective_from,effective_to)');
        DB::statement('CREATE TABLE public_holidays (id INTEGER PRIMARY KEY AUTOINCREMENT,holiday_date DATE NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,status TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INTEGER NOT NULL,notes TEXT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX public_holidays_status_index ON public_holidays(status)');
    }

    private function assertSeededConfigurationIsUnchanged(): void
    {
        $attendance = Schema::hasTable('attendance_policies') ? DB::table('attendance_policies')->get() : collect();
        $schedules = Schema::hasTable('work_schedules') ? DB::table('work_schedules')->get() : collect();
        $leave = Schema::hasTable('leave_policies') ? DB::table('leave_policies')->get() : collect();

        if ($attendance->count() !== 1 || $schedules->count() !== 1 || $leave->count() !== 1
            || $schedules->first()->code !== 'PK_OFFICE'
            || $schedules->first()->timezone !== 'Asia/Karachi'
            || (int) $schedules->first()->attendance_policy_id !== (int) $attendance->first()->id
            || $leave->first()->leave_year_mode !== 'calendar_year') {
            throw new RuntimeException('Rollback refused: seeded HR policies or schedules have been changed.');
        }
    }
};
