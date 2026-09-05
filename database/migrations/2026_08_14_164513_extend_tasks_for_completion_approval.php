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

        Schema::create('task_completion_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('task_assignment_id')->nullable()->constrained('task_assignments')->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('active_fingerprint')->nullable()->unique();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->text('completion_note')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index(['task_assignment_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index(['submitted_by_user_id', 'submitted_at'], 'task_completion_submitter_time_idx');
        });
        DB::statement("ALTER TABLE task_completion_submissions ADD CONSTRAINT task_completion_submissions_values_check CHECK (
            status IN ('pending','confirmed','returned')
            AND ((status = 'pending' AND active_fingerprint IS NOT NULL AND decided_by_user_id IS NULL AND decided_at IS NULL AND decision_reason IS NULL)
                OR (status = 'confirmed' AND active_fingerprint IS NULL AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NULL)
                OR (status = 'returned' AND active_fingerprint IS NULL AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL
                    AND decision_reason IS NOT NULL AND CHAR_LENGTH(TRIM(decision_reason)) > 0))
        )");

        Schema::create('task_completion_submission_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_completion_submission_id');
            $table->foreign('task_completion_submission_id', 'task_completion_events_submission_fk')->references('id')->on('task_completion_submissions')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['task_completion_submission_id', 'occurred_at'], 'task_completion_events_submission_occurred_index');
            $table->index(['event_type', 'occurred_at'], 'task_completion_events_type_occurred_index');
            $table->index(['actor_user_id', 'occurred_at'], 'task_completion_events_actor_occurred_index');
        });
        DB::statement("ALTER TABLE task_completion_submission_events ADD CONSTRAINT task_completion_submission_events_values_check CHECK (
            event_type IN ('submitted','confirmed','returned')
            AND (event_type <> 'returned' OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0))
        )");
    }

    public function down(): void
    {
        if ((Schema::hasTable('task_completion_submissions') && DB::table('task_completion_submissions')->exists())
            || (Schema::hasTable('task_completion_submission_events') && DB::table('task_completion_submission_events')->exists())) {
            throw new RuntimeException('Rollback refused: permanent Task completion-approval history exists.');
        }

        Schema::dropIfExists('task_completion_submission_events');
        Schema::dropIfExists('task_completion_submissions');
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE task_completion_submissions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 task_id INTEGER NOT NULL,
 task_assignment_id INTEGER NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','returned')),
 active_fingerprint VARCHAR(255) NULL UNIQUE,
 submitted_by_user_id INTEGER NOT NULL,
 submitted_at DATETIME NOT NULL,
 completion_note TEXT NULL,
 decided_by_user_id INTEGER NULL,
 decided_at DATETIME NULL,
 decision_reason TEXT NULL,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK((status = 'pending' AND active_fingerprint IS NOT NULL AND decided_by_user_id IS NULL AND decided_at IS NULL AND decision_reason IS NULL)
    OR (status = 'confirmed' AND active_fingerprint IS NULL AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NULL)
    OR (status = 'returned' AND active_fingerprint IS NULL AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL
        AND decision_reason IS NOT NULL AND length(trim(decision_reason)) > 0)),
 FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE RESTRICT,
 FOREIGN KEY(task_assignment_id) REFERENCES task_assignments(id) ON DELETE RESTRICT,
 FOREIGN KEY(submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(decided_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX task_completion_submissions_task_status_index ON task_completion_submissions(task_id,status)');
        DB::statement('CREATE INDEX task_completion_submissions_assignment_status_index ON task_completion_submissions(task_assignment_id,status)');
        DB::statement('CREATE INDEX task_completion_submissions_status_submitted_index ON task_completion_submissions(status,submitted_at)');
        DB::statement('CREATE INDEX task_completion_submissions_submitter_submitted_index ON task_completion_submissions(submitted_by_user_id,submitted_at)');

        DB::statement(<<<'SQL'
CREATE TABLE task_completion_submission_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 task_completion_submission_id INTEGER NOT NULL,
 event_type VARCHAR(30) NOT NULL CHECK(event_type IN ('submitted','confirmed','returned')),
 actor_user_id INTEGER NOT NULL,
 reason TEXT NULL,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 occurred_at DATETIME NOT NULL,
 created_at DATETIME NULL,
 CHECK(event_type <> 'returned' OR (reason IS NOT NULL AND length(trim(reason)) > 0)),
 FOREIGN KEY(task_completion_submission_id) REFERENCES task_completion_submissions(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX task_completion_events_submission_occurred_index ON task_completion_submission_events(task_completion_submission_id,occurred_at)');
        DB::statement('CREATE INDEX task_completion_events_type_occurred_index ON task_completion_submission_events(event_type,occurred_at)');
        DB::statement('CREATE INDEX task_completion_events_actor_occurred_index ON task_completion_submission_events(actor_user_id,occurred_at)');
    }
};
