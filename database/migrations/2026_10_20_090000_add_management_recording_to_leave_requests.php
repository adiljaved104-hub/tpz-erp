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
            DB::statement("ALTER TABLE leave_requests ADD COLUMN recorded_source VARCHAR(20) NOT NULL DEFAULT 'employee'");
            DB::statement('ALTER TABLE leave_requests ADD COLUMN recorded_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE leave_requests ADD COLUMN recorded_at DATETIME NULL');

            return;
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('recorded_source', 20)->default('employee');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            throw new RuntimeException('Rollback refused: management leave provenance must remain auditable.');
        }

        if (DB::table('leave_requests')->whereNotNull('recorded_by_user_id')->exists()) {
            throw new RuntimeException('Rollback refused: management-recorded Leave history contains attribution data.');
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by_user_id');
            $table->dropColumn(['recorded_source', 'recorded_at']);
        });
    }
};
