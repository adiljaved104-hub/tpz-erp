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
CREATE TABLE user_dashboard_preferences (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 dashboard_key VARCHAR(64) NOT NULL,
 layout TEXT NOT NULL CHECK (JSON_VALID(layout) AND JSON_TYPE(layout) = 'array'),
 hidden_widgets TEXT NOT NULL CHECK (JSON_VALID(hidden_widgets) AND JSON_TYPE(hidden_widgets) = 'array'),
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(user_id, dashboard_key),
 CHECK (LENGTH(TRIM(dashboard_key)) BETWEEN 1 AND 64),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);

            return;
        }

        Schema::create('user_dashboard_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('dashboard_key', 64);
            $table->json('layout');
            $table->json('hidden_widgets');
            $table->timestamps();

            $table->unique(['user_id', 'dashboard_key'], 'user_dashboard_preferences_user_dashboard_uq');
            $table->foreign('user_id', 'user_dashboard_preferences_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
ALTER TABLE user_dashboard_preferences
ADD CONSTRAINT user_dashboard_preferences_values_chk CHECK (
 CHAR_LENGTH(TRIM(dashboard_key)) BETWEEN 1 AND 64
 AND JSON_TYPE(layout) = 'ARRAY'
 AND JSON_TYPE(hidden_widgets) = 'ARRAY'
)
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('user_dashboard_preferences') && DB::table('user_dashboard_preferences')->exists()) {
            throw new RuntimeException('Rollback refused: user Dashboard preferences exist.');
        }

        Schema::dropIfExists('user_dashboard_preferences');
    }
};
