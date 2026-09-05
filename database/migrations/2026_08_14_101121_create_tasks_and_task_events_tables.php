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

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('priority', 20)->default('normal');
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('assigned_team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('linked_type', 50)->nullable();
            $table->unsignedBigInteger('linked_record_id')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['status', 'follow_up_at']);
            $table->index(['assigned_employee_id', 'status']);
            $table->index(['assigned_team_id', 'status']);
            $table->index(['priority', 'status']);
            $table->index(['linked_type', 'linked_record_id']);
            $table->index(['created_by_user_id', 'status']);
        });
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_values_check CHECK (
            status IN ('pending','assigned','in_progress','waiting','completed','cancelled')
            AND priority IN ('low','normal','high','critical')
            AND NOT (assigned_employee_id IS NOT NULL AND assigned_team_id IS NOT NULL)
            AND ((linked_type IS NULL AND linked_record_id IS NULL) OR (linked_type IS NOT NULL AND linked_record_id IS NOT NULL))
            AND (linked_type IS NULL OR linked_type IN ('order','customer_return','safet_claim','warranty_repair','internal_repair','complaint','damaged_stock_event','product','purchase','stock_transfer','employee'))
            AND ((status = 'completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL)
                OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND completed_at IS NULL)
                OR (status NOT IN ('completed','cancelled') AND completed_at IS NULL AND cancelled_at IS NULL))
        )");

        Schema::create('task_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['task_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE task_events ADD CONSTRAINT task_events_values_check CHECK (
            event_type IN ('created','assigned','reassigned','started','status_changed','waiting','resumed','due_date_changed','priority_changed','comment_added','completed','reopened','cancelled')
            AND (from_status IS NULL OR from_status IN ('pending','assigned','in_progress','waiting','completed','cancelled'))
            AND (to_status IS NULL OR to_status IN ('pending','assigned','in_progress','waiting','completed','cancelled'))
        )");
    }

    public function down(): void
    {
        foreach (['task_events', 'tasks'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains permanent Task history.");
            }
        }

        Schema::dropIfExists('task_events');
        Schema::dropIfExists('tasks');
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE tasks (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 reference VARCHAR(20) NOT NULL UNIQUE,
 title VARCHAR(255) NOT NULL,
 description TEXT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','assigned','in_progress','waiting','completed','cancelled')),
 priority VARCHAR(20) NOT NULL DEFAULT 'normal' CHECK(priority IN ('low','normal','high','critical')),
 assigned_employee_id INTEGER NULL,
 assigned_team_id INTEGER NULL,
 created_by_user_id INTEGER NOT NULL,
 due_at DATETIME NULL,
 follow_up_at DATETIME NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 linked_type VARCHAR(50) NULL CHECK(linked_type IS NULL OR linked_type IN ('order','customer_return','safet_claim','warranty_repair','internal_repair','complaint','damaged_stock_event','product','purchase','stock_transfer','employee')),
 linked_record_id INTEGER NULL,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK(NOT (assigned_employee_id IS NOT NULL AND assigned_team_id IS NOT NULL)),
 CHECK((linked_type IS NULL AND linked_record_id IS NULL) OR (linked_type IS NOT NULL AND linked_record_id IS NOT NULL)),
 CHECK((status = 'completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL)
    OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND completed_at IS NULL)
    OR (status NOT IN ('completed','cancelled') AND completed_at IS NULL AND cancelled_at IS NULL)),
 FOREIGN KEY(assigned_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(assigned_team_id) REFERENCES teams(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX tasks_status_due_index ON tasks(status,due_at)');
        DB::statement('CREATE INDEX tasks_status_follow_up_index ON tasks(status,follow_up_at)');
        DB::statement('CREATE INDEX tasks_employee_status_index ON tasks(assigned_employee_id,status)');
        DB::statement('CREATE INDEX tasks_team_status_index ON tasks(assigned_team_id,status)');
        DB::statement('CREATE INDEX tasks_priority_status_index ON tasks(priority,status)');
        DB::statement('CREATE INDEX tasks_linked_record_index ON tasks(linked_type,linked_record_id)');
        DB::statement('CREATE INDEX tasks_created_by_status_index ON tasks(created_by_user_id,status)');

        DB::statement(<<<'SQL'
CREATE TABLE task_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 task_id INTEGER NOT NULL,
 event_type VARCHAR(40) NOT NULL CHECK(event_type IN ('created','assigned','reassigned','started','status_changed','waiting','resumed','due_date_changed','priority_changed','comment_added','completed','reopened','cancelled')),
 from_status VARCHAR(30) NULL CHECK(from_status IS NULL OR from_status IN ('pending','assigned','in_progress','waiting','completed','cancelled')),
 to_status VARCHAR(30) NULL CHECK(to_status IS NULL OR to_status IN ('pending','assigned','in_progress','waiting','completed','cancelled')),
 actor_user_id INTEGER NOT NULL,
 note TEXT NULL,
 old_values TEXT NULL,
 new_values TEXT NULL,
 idempotency_key VARCHAR(36) NULL UNIQUE,
 occurred_at DATETIME NOT NULL,
 created_at DATETIME NULL,
 FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX task_events_task_occurred_index ON task_events(task_id,occurred_at)');
        DB::statement('CREATE INDEX task_events_type_occurred_index ON task_events(event_type,occurred_at)');
        DB::statement('CREATE INDEX task_events_actor_occurred_index ON task_events(actor_user_id,occurred_at)');
    }
};
