<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('source_company_fingerprint', 64);
            $table->string('source_format', 20);
            $table->string('source_version', 80)->nullable();
            $table->string('qbxml_version', 20)->nullable();
            $table->string('private_file_path', 512);
            $table->char('file_sha256', 64);
            $table->string('status', 24)->default('uploaded');
            $table->boolean('dry_run')->default(true);
            $table->json('checkpoint')->nullable();
            $table->json('error_summary')->nullable();
            $table->unsignedBigInteger('record_count')->default(0);
            $table->unsignedBigInteger('staged_count')->default(0);
            $table->unsignedBigInteger('duplicate_count')->default(0);
            $table->unsignedBigInteger('changed_count')->default(0);
            $table->unsignedBigInteger('invalid_count')->default(0);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('preview_started_at')->nullable();
            $table->timestamp('preview_finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at'], 'accounting_import_batches_status_updated_idx');
            $table->index(['source_company_fingerprint', 'created_at'], 'accounting_import_batches_company_created_idx');
        });

        Schema::create('accounting_import_source_identities', function (Blueprint $table): void {
            $table->id();
            $table->char('source_company_fingerprint', 64);
            $table->string('source_object_type', 64);
            $table->string('source_record_id', 191);
            $table->string('latest_edit_sequence', 191)->nullable();
            $table->char('latest_payload_sha256', 64);
            $table->timestamps();
            $table->unique(
                ['source_company_fingerprint', 'source_object_type', 'source_record_id'],
                'accounting_import_identity_source_uq',
            );
        });

        Schema::create('accounting_import_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('accounting_import_batches')->cascadeOnDelete();
            $table->foreignId('source_identity_id')->constrained('accounting_import_source_identities')->restrictOnDelete();
            $table->char('payload_sha256', 64);
            $table->string('source_edit_sequence', 191)->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->date('source_transaction_date')->nullable();
            $table->date('source_due_date')->nullable();
            $table->char('source_currency', 3)->nullable();
            $table->decimal('source_exchange_rate', 18, 8)->nullable();
            $table->json('record_payload');
            $table->string('status', 24)->default('previewed');
            $table->json('validation_errors')->nullable();
            $table->string('target_type', 80)->nullable();
            $table->string('target_record_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'source_identity_id'], 'accounting_import_batch_item_source_uq');
            $table->index(['batch_id', 'status', 'id'], 'accounting_import_batch_item_status_idx');
            $table->index(['source_transaction_date', 'source_currency'], 'accounting_import_batch_item_date_currency_idx');
        });

        Schema::create('accounting_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('accounting_import_batches')->cascadeOnDelete();
            $table->string('entity_type', 40);
            $table->string('source_key', 191);
            $table->string('target_type', 60)->nullable();
            $table->string('target_record_id', 100)->nullable();
            $table->string('status', 20)->default('unmapped');
            $table->foreignId('mapped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'entity_type', 'source_key'], 'accounting_import_mapping_source_uq');
            $table->index(['batch_id', 'entity_type', 'status'], 'accounting_import_mapping_status_idx');
        });

        Schema::create('accounting_import_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_item_id')->constrained('accounting_import_batch_items')->cascadeOnDelete();
            $table->string('relationship_type', 60);
            $table->string('linked_object_type', 64)->nullable();
            $table->string('linked_source_record_id', 191);
            $table->string('linked_line_id', 191)->nullable();
            $table->decimal('applied_amount', 18, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->json('source_reference')->nullable();
            $table->timestamps();
            $table->index(['batch_item_id', 'relationship_type'], 'accounting_import_link_item_type_idx');
            $table->index(['linked_object_type', 'linked_source_record_id'], 'accounting_import_link_source_idx');
        });

        Schema::create('accounting_import_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('accounting_import_batches')->cascadeOnDelete();
            $table->string('report_type', 64);
            $table->string('account_source_id', 191)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('source_record_count')->nullable();
            $table->decimal('source_debits', 20, 2)->nullable();
            $table->decimal('source_credits', 20, 2)->nullable();
            $table->decimal('target_debits', 20, 2)->nullable();
            $table->decimal('target_credits', 20, 2)->nullable();
            $table->json('difference_summary')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->index(['batch_id', 'report_type', 'currency'], 'accounting_import_recon_batch_type_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_import_reconciliations');
        Schema::dropIfExists('accounting_import_links');
        Schema::dropIfExists('accounting_import_mappings');
        Schema::dropIfExists('accounting_import_batch_items');
        Schema::dropIfExists('accounting_import_source_identities');
        Schema::dropIfExists('accounting_import_batches');
    }
};
