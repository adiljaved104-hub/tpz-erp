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
            DB::statement(<<<'SQL'
CREATE TABLE user_ui_preferences (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 preference_key VARCHAR(100) NOT NULL,
 preference_value TEXT NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(user_id, preference_key),
 CHECK(LENGTH(TRIM(preference_key)) BETWEEN 1 AND 100),
 CHECK(JSON_VALID(preference_value) AND JSON_TYPE(preference_value) = 'array'),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);

            return;
        }

        Schema::create('user_ui_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('preference_key', 100);
            $table->json('preference_value');
            $table->timestamps();
            $table->unique(['user_id', 'preference_key'], 'user_ui_preferences_user_key_uq');
        });

        DB::statement("ALTER TABLE user_ui_preferences ADD CONSTRAINT user_ui_preferences_values_chk CHECK (CHAR_LENGTH(TRIM(preference_key)) BETWEEN 1 AND 100 AND JSON_TYPE(preference_value) = 'ARRAY')");
    }

    public function down(): void
    {
        if (Schema::hasTable('user_ui_preferences') && DB::table('user_ui_preferences')->exists()) {
            throw new RuntimeException('Rollback refused: User UI preferences exist.');
        }

        Schema::dropIfExists('user_ui_preferences');
    }
};
