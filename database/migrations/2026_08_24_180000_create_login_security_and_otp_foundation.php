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
CREATE TABLE login_security_settings (
 id INTEGER PRIMARY KEY CHECK (id = 1),
 login_logo_path VARCHAR NULL,
 login_title VARCHAR NOT NULL DEFAULT 'Tech Point Zone ERP',
 login_subtitle VARCHAR NOT NULL DEFAULT 'Internal Business Management System',
 updated_by_user_id INTEGER NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement(<<<'SQL'
CREATE TABLE authentication_otp_challenges (
 id VARCHAR PRIMARY KEY,
 user_id INTEGER NOT NULL,
 purpose VARCHAR(24) NOT NULL CHECK (purpose IN ('login','two_factor','password_reset')),
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
            DB::statement('CREATE INDEX auth_otp_user_purpose_active_idx ON authentication_otp_challenges (user_id, purpose, consumed_at, expires_at)');
            DB::statement('CREATE INDEX auth_otp_purpose_created_idx ON authentication_otp_challenges (purpose, created_at)');
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('email_two_factor_enabled_at')->nullable()->after('email_verified_at');
            });

            return;
        }

        Schema::create('login_security_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('login_logo_path')->nullable();
            $table->string('login_title')->default('Tech Point Zone ERP');
            $table->string('login_subtitle')->default('Internal Business Management System');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('authentication_otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 24);
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->char('requested_ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'consumed_at', 'expires_at'], 'auth_otp_user_purpose_active_idx');
            $table->index(['purpose', 'created_at'], 'auth_otp_purpose_created_idx');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_two_factor_enabled_at')->nullable()->after('email_verified_at');
        });

        DB::statement('ALTER TABLE login_security_settings ADD CONSTRAINT login_security_settings_singleton_check CHECK (id = 1)');
        DB::statement("ALTER TABLE authentication_otp_challenges ADD CONSTRAINT authentication_otp_purpose_check CHECK (purpose IN ('login','two_factor','password_reset'))");
        DB::statement('ALTER TABLE authentication_otp_challenges ADD CONSTRAINT authentication_otp_attempts_check CHECK (attempts BETWEEN 0 AND 5)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_two_factor_enabled_at');
        });
        Schema::dropIfExists('authentication_otp_challenges');
        Schema::dropIfExists('login_security_settings');
    }
};
