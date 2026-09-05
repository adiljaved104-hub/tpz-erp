<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTables();
        } else {
            $this->createMysqlTables();
        }

        $this->backfillLegacyEmployeeAssignments();
    }

    public function down(): void
    {
        if (Schema::hasTable('task_assignments')) {
            $hasApplicationAssignments = DB::table('task_assignments')
                ->where('backfilled_from_legacy', false)
                ->exists();
            $hasPostBackfillHistory = Schema::hasTable('task_assignment_events')
                && DB::table('task_assignment_events')->where('backfilled_from_legacy', false)->exists();

            if ($hasApplicationAssignments || $hasPostBackfillHistory) {
                throw new RuntimeException('Rollback refused: Task assignment data exists that cannot be represented safely by the legacy single-assignee columns.');
            }
        }

        Schema::dropIfExists('task_assignment_events');
        Schema::dropIfExists('task_assignments');
    }

    private function createMysqlTables(): void
    {
        Schema::create('task_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('team_id_at_assignment')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('team_name_at_assignment')->nullable();
            $table->string('status', 30)->default('assigned');
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->boolean('backfilled_from_legacy')->default(false);
            $table->timestamps();

            $table->unique(['task_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
            $table->index(['team_id_at_assignment', 'status']);
            $table->index(['task_id', 'status']);
        });
        DB::statement("ALTER TABLE task_assignments ADD CONSTRAINT task_assignments_values_check CHECK (
            status IN ('assigned','in_progress','waiting','completed','cancelled','removed')
            AND ((status = 'completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL AND removed_at IS NULL)
                OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND completed_at IS NULL AND removed_at IS NULL)
                OR (status = 'removed' AND removed_at IS NOT NULL AND completed_at IS NULL AND cancelled_at IS NULL)
                OR (status IN ('assigned','in_progress','waiting') AND completed_at IS NULL AND cancelled_at IS NULL AND removed_at IS NULL))
        )");

        Schema::create('task_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_assignment_id')->constrained('task_assignments')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->boolean('backfilled_from_legacy')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['task_assignment_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE task_assignment_events ADD CONSTRAINT task_assignment_events_values_check CHECK (
            event_type IN ('assigned','started','waiting','resumed','completed','reopened','cancelled','removed','comment_added')
            AND (from_status IS NULL OR from_status IN ('assigned','in_progress','waiting','completed','cancelled','removed'))
            AND (to_status IS NULL OR to_status IN ('assigned','in_progress','waiting','completed','cancelled','removed'))
        )");
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE task_assignments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 task_id INTEGER NOT NULL,
 employee_id INTEGER NOT NULL,
 team_id_at_assignment INTEGER NULL,
 team_name_at_assignment VARCHAR(255) NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'assigned' CHECK(status IN ('assigned','in_progress','waiting','completed','cancelled','removed')),
 follow_up_at DATETIME NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 removed_at DATETIME NULL,
 assigned_by_user_id INTEGER NOT NULL,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 backfilled_from_legacy TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(task_id, employee_id),
 CHECK((status = 'completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL AND removed_at IS NULL)
    OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND completed_at IS NULL AND removed_at IS NULL)
    OR (status = 'removed' AND removed_at IS NOT NULL AND completed_at IS NULL AND cancelled_at IS NULL)
    OR (status IN ('assigned','in_progress','waiting') AND completed_at IS NULL AND cancelled_at IS NULL AND removed_at IS NULL)),
 FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE RESTRICT,
 FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(team_id_at_assignment) REFERENCES teams(id) ON DELETE RESTRICT,
 FOREIGN KEY(assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX task_assignments_employee_status_index ON task_assignments(employee_id,status)');
        DB::statement('CREATE INDEX task_assignments_team_status_index ON task_assignments(team_id_at_assignment,status)');
        DB::statement('CREATE INDEX task_assignments_task_status_index ON task_assignments(task_id,status)');

        DB::statement(<<<'SQL'
CREATE TABLE task_assignment_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 task_assignment_id INTEGER NOT NULL,
 event_type VARCHAR(40) NOT NULL CHECK(event_type IN ('assigned','started','waiting','resumed','completed','reopened','cancelled','removed','comment_added')),
 from_status VARCHAR(30) NULL CHECK(from_status IS NULL OR from_status IN ('assigned','in_progress','waiting','completed','cancelled','removed')),
 to_status VARCHAR(30) NULL CHECK(to_status IS NULL OR to_status IN ('assigned','in_progress','waiting','completed','cancelled','removed')),
 actor_user_id INTEGER NOT NULL,
 note TEXT NULL,
 old_values TEXT NULL,
 new_values TEXT NULL,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 backfilled_from_legacy TINYINT(1) NOT NULL DEFAULT 0,
 occurred_at DATETIME NOT NULL,
 created_at DATETIME NULL,
 FOREIGN KEY(task_assignment_id) REFERENCES task_assignments(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX task_assignment_events_assignment_occurred_index ON task_assignment_events(task_assignment_id,occurred_at)');
        DB::statement('CREATE INDEX task_assignment_events_type_occurred_index ON task_assignment_events(event_type,occurred_at)');
        DB::statement('CREATE INDEX task_assignment_events_actor_occurred_index ON task_assignment_events(actor_user_id,occurred_at)');
    }

    private function backfillLegacyEmployeeAssignments(): void
    {
        DB::table('tasks')
            ->join('employees', 'employees.id', '=', 'tasks.assigned_employee_id')
            ->leftJoin('teams', 'teams.id', '=', 'employees.team_id')
            ->select([
                'tasks.id', 'tasks.status', 'tasks.follow_up_at', 'tasks.started_at', 'tasks.completed_at',
                'tasks.cancelled_at', 'tasks.created_by_user_id', 'tasks.created_at', 'tasks.updated_at',
                'employees.id as employee_id', 'employees.team_id', 'teams.name as team_name',
            ])
            ->orderBy('tasks.id')
            ->each(function (object $task): void {
                $status = $task->status === 'pending' ? 'assigned' : $task->status;
                $assignmentId = DB::table('task_assignments')->insertGetId([
                    'task_id' => $task->id,
                    'employee_id' => $task->employee_id,
                    'team_id_at_assignment' => $task->team_id,
                    'team_name_at_assignment' => $task->team_name,
                    'status' => $status,
                    'follow_up_at' => $task->follow_up_at,
                    'started_at' => $task->started_at,
                    'completed_at' => $task->completed_at,
                    'cancelled_at' => $task->cancelled_at,
                    'removed_at' => null,
                    'assigned_by_user_id' => $task->created_by_user_id,
                    'idempotency_key' => (string) Str::uuid(),
                    'backfilled_from_legacy' => true,
                    'created_at' => $task->created_at,
                    'updated_at' => $task->updated_at,
                ]);

                DB::table('task_assignment_events')->insert([
                    'task_assignment_id' => $assignmentId,
                    'event_type' => 'assigned',
                    'from_status' => null,
                    'to_status' => $status,
                    'actor_user_id' => $task->created_by_user_id,
                    'note' => 'Backfilled from the legacy Task assignment.',
                    'old_values' => null,
                    'new_values' => null,
                    'idempotency_key' => (string) Str::uuid(),
                    'backfilled_from_legacy' => true,
                    'occurred_at' => $task->created_at,
                    'created_at' => $task->created_at,
                ]);
            });
    }
};
