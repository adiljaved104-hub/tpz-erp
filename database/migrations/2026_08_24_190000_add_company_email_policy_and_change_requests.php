<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_security_settings', function (Blueprint $table): void {
            $table->string('allowed_login_email_domain')->default('techpointzone.com')->after('login_subtitle');
            $table->timestamp('legacy_owner_email_transition_completed_at')->nullable()->after('allowed_login_email_domain');
        });

        $this->replaceOtpPurposeConstraint(true);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE login_email_change_requests (
 id VARCHAR PRIMARY KEY,
 user_id INTEGER NOT NULL,
 initiated_by_user_id INTEGER NOT NULL,
 current_email VARCHAR NOT NULL,
 new_email VARCHAR NULL,
 current_otp_challenge_id VARCHAR NULL UNIQUE,
 new_otp_challenge_id VARCHAR NULL UNIQUE,
 current_verified_at DATETIME NULL,
 new_verified_at DATETIME NULL,
 completed_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK (new_email IS NULL OR LOWER(current_email) <> LOWER(new_email)),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(initiated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(current_otp_challenge_id) REFERENCES authentication_otp_challenges(id) ON DELETE RESTRICT,
 FOREIGN KEY(new_otp_challenge_id) REFERENCES authentication_otp_challenges(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX login_email_change_user_active_idx ON login_email_change_requests (user_id, completed_at, cancelled_at, expires_at)');
            DB::statement('CREATE INDEX login_email_change_initiator_idx ON login_email_change_requests (initiated_by_user_id, created_at)');

            return;
        }

        Schema::create('login_email_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('current_email');
            $table->string('new_email')->nullable();
            $table->uuid('current_otp_challenge_id')->nullable()->unique();
            $table->uuid('new_otp_challenge_id')->nullable()->unique();
            $table->timestamp('current_verified_at')->nullable();
            $table->timestamp('new_verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('current_otp_challenge_id')->references('id')->on('authentication_otp_challenges')->restrictOnDelete();
            $table->foreign('new_otp_challenge_id')->references('id')->on('authentication_otp_challenges')->restrictOnDelete();
            $table->index(['user_id', 'completed_at', 'cancelled_at', 'expires_at'], 'login_email_change_user_active_idx');
            $table->index(['initiated_by_user_id', 'created_at'], 'login_email_change_initiator_idx');
        });

        DB::statement('ALTER TABLE login_email_change_requests ADD CONSTRAINT login_email_change_distinct_email_check CHECK (new_email IS NULL OR LOWER(current_email) <> LOWER(new_email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('login_email_change_requests');

        $this->replaceOtpPurposeConstraint(false);

        Schema::table('login_security_settings', function (Blueprint $table): void {
            $table->dropColumn(['allowed_login_email_domain', 'legacy_owner_email_transition_completed_at']);
        });
    }

    private function replaceOtpPurposeConstraint(bool $includeEmailChange): void
    {
        $purposes = $includeEmailChange
            ? "'login','two_factor','password_reset','email_change_current','email_change_new'"
            : "'login','two_factor','password_reset'";

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE authentication_otp_challenges DROP CHECK authentication_otp_purpose_check');
            DB::statement("ALTER TABLE authentication_otp_challenges ADD CONSTRAINT authentication_otp_purpose_check CHECK (purpose IN ({$purposes}))");

            return;
        }

        DB::statement(<<<SQL
CREATE TABLE authentication_otp_challenges_rebuilt (
 id VARCHAR PRIMARY KEY,
 user_id INTEGER NOT NULL,
 purpose VARCHAR(24) NOT NULL CHECK (purpose IN ({$purposes})),
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
        DB::statement('INSERT INTO authentication_otp_challenges_rebuilt SELECT * FROM authentication_otp_challenges');
        Schema::drop('authentication_otp_challenges');
        Schema::rename('authentication_otp_challenges_rebuilt', 'authentication_otp_challenges');
        DB::statement('CREATE INDEX auth_otp_user_purpose_active_idx ON authentication_otp_challenges (user_id, purpose, consumed_at, expires_at)');
        DB::statement('CREATE INDEX auth_otp_purpose_created_idx ON authentication_otp_challenges (purpose, created_at)');
    }
};
