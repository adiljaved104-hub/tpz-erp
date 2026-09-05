<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEEDED_TYPES = [
        ['code' => 'annual', 'name' => 'Annual Leave', 'category' => 'annual', 'consumes_annual_entitlement' => true, 'is_paid' => true],
        ['code' => 'unpaid', 'name' => 'Unpaid Leave', 'category' => 'unpaid', 'consumes_annual_entitlement' => false, 'is_paid' => false],
        ['code' => 'compensatory_off', 'name' => 'Compensatory Off', 'category' => 'compensatory', 'consumes_annual_entitlement' => false, 'is_paid' => true],
    ];

    public function up(): void
    {
        DB::getDriverName() === 'sqlite' ? $this->createSqliteTables() : $this->createMysqlTables();

        $now = now();
        DB::table('leave_types')->insert(array_map(fn (array $type): array => $type + [
            'status' => true, 'created_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ], self::SEEDED_TYPES));
    }

    public function down(): void
    {
        foreach (['leave_request_events', 'leave_request_days', 'leave_requests', 'leave_entitlement_adjustments', 'compensatory_offs'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains permanent Leave or Comp Off history.");
            }
        }
        if (Schema::hasTable('leave_types')) {
            $types = DB::table('leave_types')->orderBy('code')->get();
            $expectedCodes = collect(self::SEEDED_TYPES)->pluck('code')->sort()->values()->all();
            if ($types->pluck('code')->sort()->values()->all() !== $expectedCodes) {
                throw new RuntimeException('Rollback refused: Leave Types have been changed.');
            }
            DB::table('leave_types')->delete();
        }
        Schema::dropIfExists('leave_request_events');
        Schema::dropIfExists('leave_request_days');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_entitlement_adjustments');
        Schema::dropIfExists('compensatory_offs');
        Schema::dropIfExists('leave_types');
    }

    private function createMysqlTables(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('category', 30);
            $table->boolean('consumes_annual_entitlement')->default(false);
            $table->boolean('is_paid')->default(false);
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE leave_types ADD CONSTRAINT leave_types_category_check CHECK (category IN ('annual','unpaid','compensatory','sick','emergency','other'))");
        Schema::create('compensatory_offs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('earned_work_date')->nullable();
            $table->date('off_date');
            $table->string('source', 30);
            $table->string('status', 20)->default('pending');
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('reason');
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->unique(['employee_id', 'off_date']);
            $table->index(['employee_id', 'status', 'off_date'], 'comp_off_employee_status_date_index');
        });
        DB::statement("ALTER TABLE compensatory_offs ADD CONSTRAINT compensatory_offs_values_check CHECK (source IN ('scheduled_sunday','manual') AND (source <> 'scheduled_sunday' OR (earned_work_date IS NOT NULL AND off_date > earned_work_date)) AND status IN ('pending','approved','used','cancelled') AND CHAR_LENGTH(TRIM(reason)) > 0 AND ((status = 'pending' AND approved_by_user_id IS NULL AND approved_at IS NULL AND used_at IS NULL AND cancelled_at IS NULL) OR (status = 'approved' AND approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL AND used_at IS NULL AND cancelled_at IS NULL) OR (status = 'used' AND approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL AND used_at IS NOT NULL AND cancelled_at IS NULL) OR (status = 'cancelled' AND used_at IS NULL AND cancelled_at IS NOT NULL)))");
        Schema::create('leave_entitlement_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedSmallInteger('leave_year');
            $table->decimal('days', 6, 2);
            $table->text('reason');
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['employee_id', 'leave_year']);
        });
        DB::statement('ALTER TABLE leave_entitlement_adjustments ADD CONSTRAINT leave_adjustments_values_check CHECK (leave_year BETWEEN 2000 AND 2200 AND days <> 0 AND CHAR_LENGTH(TRIM(reason)) > 0)');
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('team_id_at_request')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('leave_policy_id')->constrained('leave_policies')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('requested_working_days', 6, 2);
            $table->decimal('annual_entitlement_snapshot', 6, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->timestamp('submitted_at');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'from_date']);
            $table->index(['team_id_at_request', 'status']);
            $table->index(['status', 'from_date', 'to_date']);
        });
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_values_check CHECK (to_date >= from_date AND requested_working_days > 0 AND CHAR_LENGTH(TRIM(reason)) > 0 AND status IN ('pending','approved','rejected','cancelled') AND ((status = 'pending' AND decided_by_user_id IS NULL AND decided_at IS NULL AND decision_reason IS NULL AND cancelled_at IS NULL) OR (status = 'approved' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NULL AND cancelled_at IS NULL) OR (status = 'rejected' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NOT NULL AND CHAR_LENGTH(TRIM(decision_reason)) > 0 AND cancelled_at IS NULL) OR (status = 'cancelled' AND cancelled_at IS NOT NULL)))");
        Schema::create('leave_request_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->restrictOnDelete();
            $table->date('leave_date');
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->restrictOnDelete();
            $table->foreignId('public_holiday_id')->nullable()->constrained('public_holidays')->restrictOnDelete();
            $table->foreignId('compensatory_off_id')->nullable()->constrained('compensatory_offs')->restrictOnDelete();
            $table->string('classification', 30);
            $table->boolean('counts_as_leave');
            $table->decimal('leave_units', 3, 2)->default(0);
            $table->timestamps();
            $table->unique(['leave_request_id', 'leave_date']);
            $table->index(['leave_date', 'classification']);
        });
        DB::statement("ALTER TABLE leave_request_days ADD CONSTRAINT leave_request_days_values_check CHECK (classification IN ('working_leave','schedule_off','public_holiday','compensatory_off') AND leave_units IN (0,0.5,1) AND ((counts_as_leave = 1 AND classification = 'working_leave' AND leave_units > 0) OR (counts_as_leave = 0 AND classification <> 'working_leave' AND leave_units = 0)))");
        Schema::create('leave_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->restrictOnDelete();
            $table->string('event_type', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['leave_request_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });
        DB::statement("ALTER TABLE leave_request_events ADD CONSTRAINT leave_request_events_values_check CHECK (event_type IN ('submitted','approved','rejected','cancelled') AND (from_status IS NULL OR from_status IN ('pending','approved','rejected','cancelled')) AND (to_status IS NULL OR to_status IN ('pending','approved','rejected','cancelled')) AND (event_type <> 'rejected' OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)))");
    }

    private function createSqliteTables(): void
    {
        DB::statement("CREATE TABLE leave_types (id INTEGER PRIMARY KEY AUTOINCREMENT,code VARCHAR(40) NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,category VARCHAR(30) NOT NULL CHECK(category IN ('annual','unpaid','compensatory','sick','emergency','other')),consumes_annual_entitlement TINYINT(1) NOT NULL DEFAULT 0,is_paid TINYINT(1) NOT NULL DEFAULT 0,status TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX leave_types_status_index ON leave_types(status)');
        DB::statement("CREATE TABLE compensatory_offs (id INTEGER PRIMARY KEY AUTOINCREMENT,reference VARCHAR(25) NOT NULL UNIQUE,employee_id INTEGER NOT NULL,earned_work_date DATE NULL,off_date DATE NOT NULL,source VARCHAR(30) NOT NULL CHECK(source IN ('scheduled_sunday','manual')),status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','used','cancelled')),granted_by_user_id INTEGER NOT NULL,approved_by_user_id INTEGER NULL,approved_at DATETIME NULL,used_at DATETIME NULL,cancelled_at DATETIME NULL,reason TEXT NOT NULL CHECK(length(trim(reason)) > 0),idempotency_key VARCHAR(36) NOT NULL UNIQUE,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(employee_id,off_date),CHECK(source <> 'scheduled_sunday' OR (earned_work_date IS NOT NULL AND off_date > earned_work_date)),CHECK((status = 'pending' AND approved_by_user_id IS NULL AND approved_at IS NULL AND used_at IS NULL AND cancelled_at IS NULL) OR (status = 'approved' AND approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL AND used_at IS NULL AND cancelled_at IS NULL) OR (status = 'used' AND approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL AND used_at IS NOT NULL AND cancelled_at IS NULL) OR (status = 'cancelled' AND used_at IS NULL AND cancelled_at IS NOT NULL)),FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(granted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,FOREIGN KEY(approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX comp_off_employee_status_date_index ON compensatory_offs(employee_id,status,off_date)');
        DB::statement('CREATE TABLE leave_entitlement_adjustments (id INTEGER PRIMARY KEY AUTOINCREMENT,reference VARCHAR(25) NOT NULL UNIQUE,employee_id INTEGER NOT NULL,leave_year INTEGER NOT NULL CHECK(leave_year BETWEEN 2000 AND 2200),days DECIMAL(6,2) NOT NULL CHECK(days <> 0),reason TEXT NOT NULL CHECK(length(trim(reason)) > 0),approved_by_user_id INTEGER NOT NULL,idempotency_key VARCHAR(36) NOT NULL UNIQUE,created_at DATETIME NULL,updated_at DATETIME NULL,FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX leave_adjustments_employee_year_index ON leave_entitlement_adjustments(employee_id,leave_year)');
        DB::statement("CREATE TABLE leave_requests (id INTEGER PRIMARY KEY AUTOINCREMENT,reference VARCHAR(25) NOT NULL UNIQUE,employee_id INTEGER NOT NULL,team_id_at_request INTEGER NULL,leave_type_id INTEGER NOT NULL,leave_policy_id INTEGER NOT NULL,from_date DATE NOT NULL,to_date DATE NOT NULL,requested_working_days DECIMAL(6,2) NOT NULL CHECK(requested_working_days > 0),annual_entitlement_snapshot DECIMAL(6,2) NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','cancelled')),reason TEXT NOT NULL CHECK(length(trim(reason)) > 0),submitted_at DATETIME NOT NULL,decided_by_user_id INTEGER NULL,decided_at DATETIME NULL,decision_reason TEXT NULL,cancelled_at DATETIME NULL,idempotency_key VARCHAR(36) NOT NULL UNIQUE,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(to_date >= from_date),CHECK((status = 'pending' AND decided_by_user_id IS NULL AND decided_at IS NULL AND decision_reason IS NULL AND cancelled_at IS NULL) OR (status = 'approved' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NULL AND cancelled_at IS NULL) OR (status = 'rejected' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NOT NULL AND length(trim(decision_reason)) > 0 AND cancelled_at IS NULL) OR (status = 'cancelled' AND cancelled_at IS NOT NULL)),FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(team_id_at_request) REFERENCES teams(id) ON DELETE RESTRICT,FOREIGN KEY(leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,FOREIGN KEY(leave_policy_id) REFERENCES leave_policies(id) ON DELETE RESTRICT,FOREIGN KEY(decided_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX leave_requests_employee_status_from_index ON leave_requests(employee_id,status,from_date)');
        DB::statement('CREATE INDEX leave_requests_team_status_index ON leave_requests(team_id_at_request,status)');
        DB::statement('CREATE INDEX leave_requests_status_dates_index ON leave_requests(status,from_date,to_date)');
        DB::statement("CREATE TABLE leave_request_days (id INTEGER PRIMARY KEY AUTOINCREMENT,leave_request_id INTEGER NOT NULL,leave_date DATE NOT NULL,work_schedule_id INTEGER NULL,public_holiday_id INTEGER NULL,compensatory_off_id INTEGER NULL,classification VARCHAR(30) NOT NULL CHECK(classification IN ('working_leave','schedule_off','public_holiday','compensatory_off')),counts_as_leave TINYINT(1) NOT NULL,leave_units DECIMAL(3,2) NOT NULL DEFAULT 0 CHECK(leave_units IN (0,0.5,1)),created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(leave_request_id,leave_date),CHECK((counts_as_leave = 1 AND classification = 'working_leave' AND leave_units > 0) OR (counts_as_leave = 0 AND classification <> 'working_leave' AND leave_units = 0)),FOREIGN KEY(leave_request_id) REFERENCES leave_requests(id) ON DELETE RESTRICT,FOREIGN KEY(work_schedule_id) REFERENCES work_schedules(id) ON DELETE RESTRICT,FOREIGN KEY(public_holiday_id) REFERENCES public_holidays(id) ON DELETE RESTRICT,FOREIGN KEY(compensatory_off_id) REFERENCES compensatory_offs(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX leave_request_days_date_class_index ON leave_request_days(leave_date,classification)');
        DB::statement("CREATE TABLE leave_request_events (id INTEGER PRIMARY KEY AUTOINCREMENT,leave_request_id INTEGER NOT NULL,event_type VARCHAR(20) NOT NULL CHECK(event_type IN ('submitted','approved','rejected','cancelled')),from_status VARCHAR(20) NULL CHECK(from_status IS NULL OR from_status IN ('pending','approved','rejected','cancelled')),to_status VARCHAR(20) NULL CHECK(to_status IS NULL OR to_status IN ('pending','approved','rejected','cancelled')),actor_user_id INTEGER NOT NULL,reason TEXT NULL,idempotency_key VARCHAR(36) NOT NULL UNIQUE,occurred_at DATETIME NOT NULL,created_at DATETIME NULL,CHECK(event_type <> 'rejected' OR (reason IS NOT NULL AND length(trim(reason)) > 0)),FOREIGN KEY(leave_request_id) REFERENCES leave_requests(id) ON DELETE RESTRICT,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX leave_request_events_request_occurred_index ON leave_request_events(leave_request_id,occurred_at)');
        DB::statement('CREATE INDEX leave_request_events_type_occurred_index ON leave_request_events(event_type,occurred_at)');
    }
};
