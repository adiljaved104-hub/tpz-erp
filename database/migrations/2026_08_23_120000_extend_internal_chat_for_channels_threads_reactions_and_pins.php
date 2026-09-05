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
            $this->rebuildSqliteConversations(true);
        } else {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->string('slug', 100)->nullable()->unique()->after('title');
                $table->string('description', 500)->nullable()->after('slug');
                $table->string('visibility', 20)->nullable()->after('description');
                $table->index(['type', 'visibility', 'status'], 'conversations_type_visibility_status_index');
            });
            DB::statement('ALTER TABLE conversations DROP CHECK conversations_values_check');
            DB::statement(<<<'SQL'
ALTER TABLE conversations ADD CONSTRAINT conversations_values_check CHECK (
 type IN ('direct','team','context','channel') AND status IN ('active','archived')
 AND ((type = 'direct' AND direct_fingerprint IS NOT NULL AND team_id IS NULL AND context_type IS NULL AND context_id IS NULL AND slug IS NULL AND visibility IS NULL)
 OR (type = 'team' AND direct_fingerprint IS NULL AND team_id IS NOT NULL AND context_type IS NULL AND context_id IS NULL AND slug IS NULL AND visibility IS NULL)
 OR (type = 'context' AND direct_fingerprint IS NULL AND team_id IS NULL AND context_type IS NOT NULL AND context_id IS NOT NULL AND slug IS NULL AND visibility IS NULL)
 OR (type = 'channel' AND direct_fingerprint IS NULL AND team_id IS NULL AND context_type IS NULL AND context_id IS NULL AND slug IS NOT NULL AND visibility IN ('public','private')))
 AND (context_type IS NULL OR context_type IN ('task','order','customer_return','safet_claim','complaint','warranty_repair'))
 AND ((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL) OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL)))
SQL);
        }

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->index(['reply_to_message_id', 'created_at'], 'conversation_messages_thread_index');
        });

        Schema::create('conversation_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_message_id')->constrained('conversation_messages')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('reaction', 32);
            $table->timestamps();
            $table->unique(['conversation_message_id', 'employee_id', 'reaction'], 'conversation_message_reactions_unique');
            $table->index(['conversation_message_id', 'reaction'], 'conversation_message_reactions_message_index');
        });

        Schema::create('conversation_message_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->restrictOnDelete();
            $table->foreignId('conversation_message_id')->constrained('conversation_messages')->restrictOnDelete();
            $table->foreignId('pinned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('pinned_at');
            $table->timestamps();
            $table->unique(['conversation_id', 'conversation_message_id'], 'conversation_message_pins_unique');
            $table->index(['conversation_id', 'pinned_at'], 'conversation_message_pins_conversation_index');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('conversation_message_reactions') && DB::table('conversation_message_reactions')->exists())
            || (Schema::hasTable('conversation_message_pins') && DB::table('conversation_message_pins')->exists())
            || DB::table('conversations')->where('type', 'channel')->exists()) {
            throw new RuntimeException('Rollback refused: the Chat upgrade contains permanent business history.');
        }

        Schema::dropIfExists('conversation_message_pins');
        Schema::dropIfExists('conversation_message_reactions');
        Schema::table('conversation_messages', fn (Blueprint $table) => $table->dropIndex('conversation_messages_thread_index'));

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteConversations(false);
        } else {
            DB::statement('ALTER TABLE conversations DROP CHECK conversations_values_check');
            Schema::table('conversations', function (Blueprint $table): void {
                $table->dropIndex('conversations_type_visibility_status_index');
                $table->dropUnique(['slug']);
                $table->dropColumn(['slug', 'description', 'visibility']);
            });
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_values_check CHECK (type IN ('direct','team','context') AND status IN ('active','archived'))");
        }
    }

    private function rebuildSqliteConversations(bool $upgrade): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');
        try {
            DB::statement('ALTER TABLE conversations RENAME TO conversations_chat_previous');
            $channelColumns = $upgrade ? 'slug VARCHAR(100) NULL UNIQUE, description VARCHAR(500) NULL, visibility VARCHAR(20) NULL,' : '';
            $types = $upgrade ? "'direct','team','context','channel'" : "'direct','team','context'";
            $channelShape = $upgrade ? " OR (type = 'channel' AND direct_fingerprint IS NULL AND team_id IS NULL AND context_type IS NULL AND context_id IS NULL AND slug IS NOT NULL AND visibility IN ('public','private'))" : '';
            $nonChannelShape = $upgrade ? ' AND slug IS NULL AND visibility IS NULL' : '';
            DB::statement("CREATE TABLE conversations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(20) NOT NULL CHECK(type IN ({$types})), title VARCHAR(255) NULL,
 {$channelColumns} team_id INTEGER NULL UNIQUE, context_type VARCHAR(50) NULL CHECK(context_type IS NULL OR context_type IN ('task','order','customer_return','safet_claim','complaint','warranty_repair')),
 context_id INTEGER NULL, direct_fingerprint CHAR(64) NULL UNIQUE, status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
 created_by_user_id INTEGER NOT NULL, archived_by_user_id INTEGER NULL, archived_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
 UNIQUE(context_type,context_id),
 CHECK((type = 'direct' AND direct_fingerprint IS NOT NULL AND team_id IS NULL AND context_type IS NULL AND context_id IS NULL{$nonChannelShape})
 OR (type = 'team' AND direct_fingerprint IS NULL AND team_id IS NOT NULL AND context_type IS NULL AND context_id IS NULL{$nonChannelShape})
 OR (type = 'context' AND direct_fingerprint IS NULL AND team_id IS NULL AND context_type IS NOT NULL AND context_id IS NOT NULL{$nonChannelShape}){$channelShape}),
 CHECK((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL) OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL)),
 FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE RESTRICT, FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(archived_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
            $columns = 'id,type,title,team_id,context_type,context_id,direct_fingerprint,status,created_by_user_id,archived_by_user_id,archived_at,created_at,updated_at';
            $target = $upgrade ? 'id,type,title,slug,description,visibility,team_id,context_type,context_id,direct_fingerprint,status,created_by_user_id,archived_by_user_id,archived_at,created_at,updated_at' : $columns;
            $source = $upgrade ? 'id,type,title,NULL,NULL,NULL,team_id,context_type,context_id,direct_fingerprint,status,created_by_user_id,archived_by_user_id,archived_at,created_at,updated_at' : $columns;
            DB::statement("INSERT INTO conversations ({$target}) SELECT {$source} FROM conversations_chat_previous");
            DB::statement('DROP TABLE conversations_chat_previous');
            DB::statement('CREATE INDEX conversations_status_updated_index ON conversations(status,updated_at)');
            if ($upgrade) {
                DB::statement('CREATE INDEX conversations_type_visibility_status_index ON conversations(type,visibility,status)');
            }
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
