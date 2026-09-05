<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONTEXT_TYPES = "'task','order','customer_return','safet_claim','complaint','warranty_repair'";

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTables();

            return;
        }

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->foreignId('team_id')->nullable()->unique()->constrained('teams')->restrictOnDelete();
            $table->string('context_type', 50)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->char('direct_fingerprint', 64)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['context_type', 'context_id'], 'conversations_context_unique');
            $table->index(['status', 'updated_at'], 'conversations_status_updated_index');
        });
        DB::statement('ALTER TABLE conversations ADD CONSTRAINT conversations_values_check CHECK ('.
            "type IN ('direct','team','context') AND status IN ('active','archived') ".
            'AND ((type = \'direct\' AND direct_fingerprint IS NOT NULL AND team_id IS NULL AND context_type IS NULL AND context_id IS NULL) '.
            'OR (type = \'team\' AND direct_fingerprint IS NULL AND team_id IS NOT NULL AND context_type IS NULL AND context_id IS NULL) '.
            'OR (type = \'context\' AND direct_fingerprint IS NULL AND team_id IS NULL AND context_type IS NOT NULL AND context_id IS NOT NULL)) '.
            'AND (context_type IS NULL OR context_type IN ('.self::CONTEXT_TYPES.')) '.
            "AND ((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL) OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL)))");

        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->restrictOnDelete();
            $table->foreignId('sender_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('conversation_messages')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['conversation_id', 'created_at'], 'conversation_messages_conversation_created_index');
            $table->index(['sender_employee_id', 'created_at'], 'conversation_messages_sender_created_index');
        });
        DB::statement('ALTER TABLE conversation_messages ADD CONSTRAINT conversation_messages_body_check CHECK (CHAR_LENGTH(TRIM(body)) BETWEEN 1 AND 5000)');

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->foreignId('last_read_message_id')->nullable()->constrained('conversation_messages')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'employee_id'], 'conversation_participants_unique');
            $table->index(['employee_id', 'left_at'], 'conversation_participants_employee_active_index');
        });
        DB::statement('ALTER TABLE conversation_participants ADD CONSTRAINT conversation_participants_dates_check CHECK (left_at IS NULL OR left_at >= joined_at)');
    }

    public function down(): void
    {
        foreach (['conversation_messages', 'conversation_participants', 'conversations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains permanent Chat history.");
            }
        }

        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE conversations (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 type VARCHAR(20) NOT NULL CHECK(type IN ('direct','team','context')),
 title VARCHAR(255) NULL,
 team_id INTEGER NULL UNIQUE,
 context_type VARCHAR(50) NULL CHECK(context_type IS NULL OR context_type IN ('task','order','customer_return','safet_claim','complaint','warranty_repair')),
 context_id INTEGER NULL,
 direct_fingerprint CHAR(64) NULL UNIQUE,
 status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
 created_by_user_id INTEGER NOT NULL,
 archived_by_user_id INTEGER NULL,
 archived_at DATETIME NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(context_type,context_id),
 CHECK((type = 'direct' AND direct_fingerprint IS NOT NULL AND team_id IS NULL AND context_type IS NULL AND context_id IS NULL)
    OR (type = 'team' AND direct_fingerprint IS NULL AND team_id IS NOT NULL AND context_type IS NULL AND context_id IS NULL)
    OR (type = 'context' AND direct_fingerprint IS NULL AND team_id IS NULL AND context_type IS NOT NULL AND context_id IS NOT NULL)),
 CHECK((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL)
    OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL)),
 FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(archived_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX conversations_status_updated_index ON conversations(status,updated_at)');
        DB::statement(<<<'SQL'
CREATE TABLE conversation_messages (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 conversation_id INTEGER NOT NULL,
 sender_employee_id INTEGER NOT NULL,
 reply_to_message_id INTEGER NULL,
 body TEXT NOT NULL CHECK(length(trim(body)) BETWEEN 1 AND 5000),
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE RESTRICT,
 FOREIGN KEY(sender_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(reply_to_message_id) REFERENCES conversation_messages(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX conversation_messages_conversation_created_index ON conversation_messages(conversation_id,created_at)');
        DB::statement('CREATE INDEX conversation_messages_sender_created_index ON conversation_messages(sender_employee_id,created_at)');
        DB::statement(<<<'SQL'
CREATE TABLE conversation_participants (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 conversation_id INTEGER NOT NULL,
 employee_id INTEGER NOT NULL,
 joined_at DATETIME NOT NULL,
 left_at DATETIME NULL,
 last_read_message_id INTEGER NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(conversation_id,employee_id),
 CHECK(left_at IS NULL OR left_at >= joined_at),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE RESTRICT,
 FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(last_read_message_id) REFERENCES conversation_messages(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX conversation_participants_employee_active_index ON conversation_participants(employee_id,left_at)');
    }
};
