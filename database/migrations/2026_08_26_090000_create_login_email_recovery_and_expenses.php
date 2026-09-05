<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->replaceOtpPurposeConstraint(true);

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTables();

            return;
        }

        Schema::create('login_email_recovery_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('current_email');
            $table->string('new_email')->nullable();
            $table->text('reason');
            $table->timestamp('owner_password_verified_at');
            $table->uuid('owner_2fa_challenge_id')->nullable()->unique();
            $table->timestamp('owner_2fa_verified_at')->nullable();
            $table->uuid('new_otp_challenge_id')->nullable()->unique();
            $table->timestamp('new_verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('owner_2fa_challenge_id', 'login_email_recovery_owner_2fa_fk')->references('id')->on('authentication_otp_challenges')->restrictOnDelete();
            $table->foreign('new_otp_challenge_id', 'login_email_recovery_new_otp_fk')->references('id')->on('authentication_otp_challenges')->restrictOnDelete();
            $table->index(['user_id', 'completed_at', 'cancelled_at', 'expires_at'], 'login_email_recovery_target_active_idx');
            $table->index(['initiated_by_user_id', 'created_at'], 'login_email_recovery_owner_idx');
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->date('expense_date');
            $table->string('category', 40);
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->string('cost_center', 24);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('reference_note', 500)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['cost_center', 'expense_date', 'voided_at'], 'expenses_center_date_active_idx');
            $table->index(['category', 'expense_date'], 'expenses_category_date_idx');
            $table->index(['employee_id', 'expense_date'], 'expenses_employee_date_idx');
        });

        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('login_email_recovery_requests');
        $this->replaceOtpPurposeConstraint(false);
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE login_email_recovery_requests ADD CONSTRAINT login_email_recovery_distinct_users_check CHECK (user_id <> initiated_by_user_id)');
        DB::statement('ALTER TABLE login_email_recovery_requests ADD CONSTRAINT login_email_recovery_distinct_email_check CHECK (new_email IS NULL OR LOWER(current_email) <> LOWER(new_email))');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_category_check CHECK (category IN ('employee_salaries','advertising','courier_charges','office_rent','utilities','marketplace_website_costs','transportation','repairs','miscellaneous'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_cost_center_check CHECK (cost_center IN ('web_sales','marketplace','general','other'))");
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_positive_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_void_state_check CHECK ((voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL))');
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE login_email_recovery_requests (
 id VARCHAR PRIMARY KEY,
 user_id INTEGER NOT NULL,
 initiated_by_user_id INTEGER NOT NULL,
 current_email VARCHAR NOT NULL,
 new_email VARCHAR NULL,
 reason TEXT NOT NULL,
 owner_password_verified_at DATETIME NOT NULL,
 owner_2fa_challenge_id VARCHAR NULL UNIQUE,
 owner_2fa_verified_at DATETIME NULL,
 new_otp_challenge_id VARCHAR NULL UNIQUE,
 new_verified_at DATETIME NULL,
 completed_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK (user_id <> initiated_by_user_id),
 CHECK (new_email IS NULL OR LOWER(current_email) <> LOWER(new_email)),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(initiated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(owner_2fa_challenge_id) REFERENCES authentication_otp_challenges(id) ON DELETE RESTRICT,
 FOREIGN KEY(new_otp_challenge_id) REFERENCES authentication_otp_challenges(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX login_email_recovery_target_active_idx ON login_email_recovery_requests (user_id, completed_at, cancelled_at, expires_at)');
        DB::statement('CREATE INDEX login_email_recovery_owner_idx ON login_email_recovery_requests (initiated_by_user_id, created_at)');

        DB::statement(<<<'SQL'
CREATE TABLE expenses (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 expense_date DATE NOT NULL,
 category VARCHAR(40) NOT NULL CHECK (category IN ('employee_salaries','advertising','courier_charges','office_rent','utilities','marketplace_website_costs','transportation','repairs','miscellaneous')),
 description VARCHAR NOT NULL,
 amount NUMERIC(14,2) NOT NULL CHECK (amount > 0),
 cost_center VARCHAR(24) NOT NULL CHECK (cost_center IN ('web_sales','marketplace','general','other')),
 employee_id INTEGER NULL,
 reference_note VARCHAR(500) NULL,
 created_by_user_id INTEGER NOT NULL,
 updated_by_user_id INTEGER NULL,
 voided_at DATETIME NULL,
 voided_by_user_id INTEGER NULL,
 void_reason VARCHAR(500) NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK ((voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL)),
 FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(voided_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX expenses_center_date_active_idx ON expenses (cost_center, expense_date, voided_at)');
        DB::statement('CREATE INDEX expenses_category_date_idx ON expenses (category, expense_date)');
        DB::statement('CREATE INDEX expenses_employee_date_idx ON expenses (employee_id, expense_date)');
    }

    private function replaceOtpPurposeConstraint(bool $includeRecovery): void
    {
        $purposes = $includeRecovery
            ? "'login','two_factor','password_reset','email_change_current','email_change_new','email_recovery_new'"
            : "'login','two_factor','password_reset','email_change_current','email_change_new'";

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE authentication_otp_challenges DROP CHECK authentication_otp_purpose_check');
            DB::statement("ALTER TABLE authentication_otp_challenges ADD CONSTRAINT authentication_otp_purpose_check CHECK (purpose IN ({$purposes}))");

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement(<<<SQL
CREATE TABLE authentication_otp_challenges_rebuilt (
 id VARCHAR PRIMARY KEY,
 user_id INTEGER NOT NULL,
 purpose VARCHAR(24) NOT NULL CHECK (purpose IN ({$purposes})),
 code_hash VARCHAR NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
 requested_ip_hash CHAR(64) NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('INSERT INTO authentication_otp_challenges_rebuilt SELECT * FROM authentication_otp_challenges');
        Schema::drop('authentication_otp_challenges');
        Schema::rename('authentication_otp_challenges_rebuilt', 'authentication_otp_challenges');
        DB::statement('CREATE INDEX auth_otp_user_purpose_active_idx ON authentication_otp_challenges (user_id, purpose, consumed_at, expires_at)');
        DB::statement('CREATE INDEX auth_otp_purpose_created_idx ON authentication_otp_challenges (purpose, created_at)');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};
