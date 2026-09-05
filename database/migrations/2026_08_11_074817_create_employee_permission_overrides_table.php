<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('permission_key', 150);
            $table->enum('effect', ['allow', 'deny']);
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'permission_key']);
            $table->index(['employee_id', 'effect']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('employee_permission_overrides') && DB::table('employee_permission_overrides')->exists()) {
            throw new RuntimeException('Rollback refused: employee permission overrides exist.');
        }

        Schema::dropIfExists('employee_permission_overrides');
    }
};
