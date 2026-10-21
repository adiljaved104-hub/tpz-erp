<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_leave_reconciliation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees', 'id', 'hist_leave_review_employee_fk')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->constrained('users', 'id', 'hist_leave_review_actor_fk')->restrictOnDelete();
            $table->char('data_fingerprint', 64);
            $table->char('onboarding_fingerprint', 64);
            $table->json('balance_snapshot');
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->index(['employee_id', 'id'], 'hist_leave_review_employee_idx');
        });

        Schema::create('historical_leave_workflow_activations', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow', 32)->unique('hist_leave_activation_workflow_uq');
            $table->foreignId('approved_by_user_id')->constrained('users', 'id', 'hist_leave_activation_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('reviewed_employee_count');
            $table->timestamp('activated_at');
            $table->timestamps();
        });

        Schema::table('historical_leave_records', function (Blueprint $table): void {
            $table->foreignId('correction_of_record_id')->nullable()
                ->constrained('historical_leave_records', 'id', 'hist_leave_correction_parent_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Historical leave activation and correction history are permanent.');
    }
};
