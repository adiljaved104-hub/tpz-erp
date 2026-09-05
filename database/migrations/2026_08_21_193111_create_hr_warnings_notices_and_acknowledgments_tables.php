<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::getDriverName() === 'sqlite' ? $this->createSqliteTables() : $this->createMysqlTables();
    }

    public function down(): void
    {
        foreach (['hr_acknowledgments', 'hr_notice_recipients', 'hr_notices', 'employee_warnings', 'warning_categories'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains permanent HR Warning, Notice, or acknowledgment history.");
            }
        }

        foreach (['hr_acknowledgments', 'hr_notice_recipients', 'hr_notices', 'employee_warnings', 'warning_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createMysqlTables(): void
    {
        Schema::create('warning_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('employee_warnings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('warning_category_id')->constrained('warning_categories')->restrictOnDelete();
            $table->string('warning_level', 30);
            $table->string('title');
            $table->text('description');
            $table->date('issued_date');
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('acknowledgment_required')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'issued_date'], 'employee_warnings_employee_status_date_index');
            $table->index(['warning_category_id', 'issued_date'], 'employee_warnings_category_date_index');
        });
        DB::statement("ALTER TABLE employee_warnings ADD CONSTRAINT employee_warnings_values_check CHECK (warning_level IN ('verbal','written','final') AND status IN ('active','closed') AND CHAR_LENGTH(TRIM(title)) > 0 AND CHAR_LENGTH(TRIM(description)) > 0 AND ((status = 'active' AND closed_at IS NULL AND closed_by_user_id IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by_user_id IS NOT NULL)))");

        Schema::create('hr_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->string('title');
            $table->text('content');
            $table->string('audience_type', 20);
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('priority', 20)->default('normal');
            $table->timestamp('published_at');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('acknowledgment_required')->default(false);
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'published_at', 'expires_at'], 'hr_notices_status_publication_index');
            $table->index(['audience_type', 'team_id', 'status'], 'hr_notices_audience_status_index');
        });
        DB::statement("ALTER TABLE hr_notices ADD CONSTRAINT hr_notices_values_check CHECK (audience_type IN ('all','team','selected') AND priority IN ('normal','important') AND status IN ('active','archived') AND CHAR_LENGTH(TRIM(title)) > 0 AND CHAR_LENGTH(TRIM(content)) > 0 AND ((audience_type = 'team' AND team_id IS NOT NULL) OR (audience_type <> 'team' AND team_id IS NULL)) AND (expires_at IS NULL OR expires_at >= published_at) AND ((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL) OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL)))");

        Schema::create('hr_notice_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hr_notice_id')->constrained('hr_notices')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['hr_notice_id', 'employee_id']);
            $table->index(['employee_id', 'hr_notice_id']);
        });

        Schema::create('hr_acknowledgments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_warning_id')->nullable()->constrained('employee_warnings')->restrictOnDelete();
            $table->foreignId('hr_notice_id')->nullable()->constrained('hr_notices')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('response_comment')->nullable();
            $table->longText('content_snapshot')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['employee_warning_id', 'employee_id'], 'hr_acknowledgments_warning_employee_unique');
            $table->unique(['hr_notice_id', 'employee_id'], 'hr_acknowledgments_notice_employee_unique');
            $table->index(['employee_id', 'acknowledged_at'], 'hr_acknowledgments_employee_state_index');
        });
        DB::statement('ALTER TABLE hr_acknowledgments ADD CONSTRAINT hr_acknowledgments_values_check CHECK (((employee_warning_id IS NOT NULL AND hr_notice_id IS NULL) OR (employee_warning_id IS NULL AND hr_notice_id IS NOT NULL)) AND ((acknowledged_at IS NULL AND acknowledged_by_user_id IS NULL AND content_snapshot IS NULL AND content_hash IS NULL) OR (acknowledged_at IS NOT NULL AND acknowledged_by_user_id IS NOT NULL AND content_snapshot IS NOT NULL AND content_hash IS NOT NULL AND CHAR_LENGTH(content_hash) = 64)))');
    }

    private function createSqliteTables(): void
    {
        DB::statement('CREATE TABLE warning_categories (id INTEGER PRIMARY KEY AUTOINCREMENT,name VARCHAR(255) NOT NULL,normalized_name VARCHAR(255) NOT NULL UNIQUE,status TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(status IN (0,1)),FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX warning_categories_status_index ON warning_categories(status)');
        DB::statement("CREATE TABLE employee_warnings (id INTEGER PRIMARY KEY AUTOINCREMENT,reference VARCHAR(25) NOT NULL UNIQUE,employee_id INTEGER NOT NULL,warning_category_id INTEGER NOT NULL,warning_level VARCHAR(30) NOT NULL,title VARCHAR(255) NOT NULL,description TEXT NOT NULL,issued_date DATE NOT NULL,issued_by_user_id INTEGER NOT NULL,acknowledgment_required TINYINT(1) NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'active',closed_at DATETIME NULL,closed_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(warning_level IN ('verbal','written','final') AND status IN ('active','closed') AND length(trim(title)) > 0 AND length(trim(description)) > 0 AND acknowledgment_required IN (0,1) AND ((status = 'active' AND closed_at IS NULL AND closed_by_user_id IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by_user_id IS NOT NULL))),FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(warning_category_id) REFERENCES warning_categories(id) ON DELETE RESTRICT,FOREIGN KEY(issued_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,FOREIGN KEY(closed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX employee_warnings_employee_status_date_index ON employee_warnings(employee_id,status,issued_date)');
        DB::statement('CREATE INDEX employee_warnings_category_date_index ON employee_warnings(warning_category_id,issued_date)');
        DB::statement("CREATE TABLE hr_notices (id INTEGER PRIMARY KEY AUTOINCREMENT,reference VARCHAR(25) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,content TEXT NOT NULL,audience_type VARCHAR(20) NOT NULL,team_id INTEGER NULL,priority VARCHAR(20) NOT NULL DEFAULT 'normal',published_at DATETIME NOT NULL,expires_at DATETIME NULL,acknowledgment_required TINYINT(1) NOT NULL DEFAULT 0,published_by_user_id INTEGER NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',archived_at DATETIME NULL,archived_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(audience_type IN ('all','team','selected') AND priority IN ('normal','important') AND status IN ('active','archived') AND length(trim(title)) > 0 AND length(trim(content)) > 0 AND acknowledgment_required IN (0,1) AND ((audience_type = 'team' AND team_id IS NOT NULL) OR (audience_type <> 'team' AND team_id IS NULL)) AND (expires_at IS NULL OR expires_at >= published_at) AND ((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL) OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL))),FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE RESTRICT,FOREIGN KEY(published_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,FOREIGN KEY(archived_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX hr_notices_status_publication_index ON hr_notices(status,published_at,expires_at)');
        DB::statement('CREATE INDEX hr_notices_audience_status_index ON hr_notices(audience_type,team_id,status)');
        DB::statement('CREATE TABLE hr_notice_recipients (id INTEGER PRIMARY KEY AUTOINCREMENT,hr_notice_id INTEGER NOT NULL,employee_id INTEGER NOT NULL,created_at DATETIME NULL,UNIQUE(hr_notice_id,employee_id),FOREIGN KEY(hr_notice_id) REFERENCES hr_notices(id) ON DELETE RESTRICT,FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX hr_notice_recipients_employee_notice_index ON hr_notice_recipients(employee_id,hr_notice_id)');
        DB::statement('CREATE TABLE hr_acknowledgments (id INTEGER PRIMARY KEY AUTOINCREMENT,employee_warning_id INTEGER NULL,hr_notice_id INTEGER NULL,employee_id INTEGER NOT NULL,read_at DATETIME NULL,acknowledged_at DATETIME NULL,acknowledged_by_user_id INTEGER NULL,response_comment TEXT NULL,content_snapshot TEXT NULL,content_hash VARCHAR(64) NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(employee_warning_id,employee_id),UNIQUE(hr_notice_id,employee_id),CHECK(((employee_warning_id IS NOT NULL AND hr_notice_id IS NULL) OR (employee_warning_id IS NULL AND hr_notice_id IS NOT NULL)) AND ((acknowledged_at IS NULL AND acknowledged_by_user_id IS NULL AND content_snapshot IS NULL AND content_hash IS NULL) OR (acknowledged_at IS NOT NULL AND acknowledged_by_user_id IS NOT NULL AND content_snapshot IS NOT NULL AND content_hash IS NOT NULL AND length(content_hash) = 64))),FOREIGN KEY(employee_warning_id) REFERENCES employee_warnings(id) ON DELETE RESTRICT,FOREIGN KEY(hr_notice_id) REFERENCES hr_notices(id) ON DELETE RESTRICT,FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,FOREIGN KEY(acknowledged_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)');
        DB::statement('CREATE INDEX hr_acknowledgments_employee_state_index ON hr_acknowledgments(employee_id,acknowledged_at)');
    }
};
