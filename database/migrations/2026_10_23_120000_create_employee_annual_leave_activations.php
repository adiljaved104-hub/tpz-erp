<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_annual_leave_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees', 'id', 'employee_annual_activation_employee_fk')->restrictOnDelete();
            $table->foreignId('review_id')
                ->constrained('historical_leave_reconciliation_reviews', 'id', 'employee_annual_activation_review_fk')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')
                ->constrained('users', 'id', 'employee_annual_activation_actor_fk')->restrictOnDelete();
            $table->char('review_fingerprint', 64);
            $table->timestamp('activated_at');
            $table->timestamps();
            $table->index(['employee_id', 'id'], 'employee_annual_activation_employee_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Employee annual leave activation approvals are permanent audit history.');
    }
};
