<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_return_exception_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_item_id')->constrained('customer_return_items', 'id', 'crec_item_fk')->restrictOnDelete();
            $table->foreignId('finding_id')->constrained('customer_return_findings', 'id', 'crec_finding_fk')->restrictOnDelete();
            $table->foreignId('evidence_id')->constrained('customer_return_evidence', 'id', 'crec_evidence_fk')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('open_key', 80)->nullable()->unique('crec_open_key_uq');
            $table->string('status', 40);
            $table->string('original_qc_result', 20)->nullable();
            $table->string('new_problem_type', 40);
            $table->unsignedInteger('affected_quantity');
            $table->string('protection_status', 40)->default('not_held');
            $table->text('protection_reason')->nullable();
            $table->uuid('hold_key')->unique('crec_hold_key_uq');
            $table->foreignId('inventory_reservation_id')->nullable()->constrained('inventory_reservations', 'id', 'crec_reservation_fk')->restrictOnDelete();
            $table->foreignId('damaged_stock_event_id')->nullable()->constrained('damaged_stock_events', 'id', 'crec_damage_fk')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users', 'id', 'crec_assigned_fk')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users', 'id', 'crec_reviewer_fk')->restrictOnDelete();
            $table->uuid('resolution_key')->nullable()->unique('crec_resolution_key_uq');
            $table->string('resolution', 40)->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['customer_return_item_id', 'kind', 'status'], 'crec_item_kind_status_idx');
            $table->index(['status', 'id'], 'crec_status_idx');
        });

        Schema::create('customer_return_exception_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('customer_return_exception_cases', 'id', 'cree_case_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', 'id', 'cree_actor_fk')->restrictOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained('customer_return_findings', 'id', 'cree_finding_fk')->restrictOnDelete();
            $table->string('action', 40);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['case_id', 'id'], 'cree_case_history_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Return exception cases and decisions are permanent audit history.');
    }
};
