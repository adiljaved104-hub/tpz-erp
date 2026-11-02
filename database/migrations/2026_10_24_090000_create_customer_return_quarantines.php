<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_returns', 'workflow_payload_hash')) {
            Schema::table('customer_returns', function (Blueprint $table): void {
                $table->char('workflow_payload_hash', 64)->nullable();
            });
        }

        if (Schema::hasTable('customer_return_quarantines')) {
            return;
        }

        Schema::create('customer_return_quarantines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_item_id')->unique('crq_item_unique')->constrained('customer_return_items', 'id', 'crq_item_fk')->restrictOnDelete();
            $table->foreignId('finding_id')->nullable()->unique('crq_finding_unique')->constrained('customer_return_findings', 'id', 'crq_finding_fk')->restrictOnDelete();
            $table->foreignId('custody_warehouse_id')->constrained('warehouses', 'id', 'crq_warehouse_fk')->restrictOnDelete();
            $table->unsignedInteger('expected_quantity');
            $table->unsignedInteger('actual_custody_quantity')->nullable();
            $table->string('problem_type', 40);
            $table->string('actual_product_name')->nullable();
            $table->string('actual_serial_number')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 24)->default('awaiting_review');
            $table->string('disposition', 40)->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users', 'id', 'crq_recorded_by_fk')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users', 'id', 'crq_reviewed_by_fk')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('review_key')->nullable()->unique('crq_review_key_unique');
            $table->timestamps();
            $table->index(['status', 'id'], 'crq_status_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Return quarantine and review records are permanent audit history.');
    }
};
