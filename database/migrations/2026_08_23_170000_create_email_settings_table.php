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
CREATE TABLE email_settings (
 id INTEGER PRIMARY KEY CHECK (id = 1),
 enabled INTEGER NOT NULL DEFAULT 0,
 smtp_host VARCHAR NULL,
 smtp_port INTEGER NULL CHECK (smtp_port IS NULL OR smtp_port BETWEEN 1 AND 65535),
 encryption VARCHAR(10) NULL CHECK (encryption IS NULL OR encryption IN ('tls','ssl')),
 smtp_username VARCHAR NULL,
 smtp_password_encrypted TEXT NULL,
 from_email VARCHAR NOT NULL,
 from_name VARCHAR NOT NULL,
 last_successful_test_at DATETIME NULL,
 last_failed_test_at DATETIME NULL,
 updated_by_user_id INTEGER NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK (enabled = 0 OR (smtp_host IS NOT NULL AND smtp_port IS NOT NULL AND encryption IS NOT NULL AND smtp_username IS NOT NULL AND smtp_password_encrypted IS NOT NULL))
)
SQL);

            return;
        }

        Schema::create('email_settings', function (Blueprint $table): void {
            // This is a singleton row keyed as id=1. Keeping the key
            // non-incrementing allows MySQL to enforce that invariant in a
            // CHECK constraint (auto-increment columns cannot be referenced).
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('encryption', 10)->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password_encrypted')->nullable();
            $table->string('from_email');
            $table->string('from_name');
            $table->timestamp('last_successful_test_at')->nullable();
            $table->timestamp('last_failed_test_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE email_settings ADD CONSTRAINT email_settings_values_check CHECK (id = 1 AND (smtp_port IS NULL OR smtp_port BETWEEN 1 AND 65535) AND (encryption IS NULL OR encryption IN ('tls','ssl')) AND (enabled = 0 OR (smtp_host IS NOT NULL AND smtp_port IS NOT NULL AND encryption IS NOT NULL AND smtp_username IS NOT NULL AND smtp_password_encrypted IS NOT NULL)))");
    }

    public function down(): void
    {
        if (Schema::hasTable('email_settings') && DB::table('email_settings')->exists()) {
            throw new RuntimeException('Rollback refused: Email Settings have been configured.');
        }

        Schema::dropIfExists('email_settings');
    }
};
