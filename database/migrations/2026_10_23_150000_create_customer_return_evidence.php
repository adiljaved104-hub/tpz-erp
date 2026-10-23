<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_return_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_item_id')->constrained('customer_return_items', 'id', 'crf_item_fk')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users', 'id', 'crf_actor_fk')->restrictOnDelete();
            $table->uuid('request_id')->unique('crf_request_unique');
            $table->string('problem_type', 40);
            $table->string('actual_product_name')->nullable();
            $table->string('actual_serial_number')->nullable();
            $table->string('physical_condition', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['customer_return_item_id', 'id'], 'crf_item_history_idx');
        });

        Schema::create('customer_return_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')->constrained('customer_return_findings', 'id', 'cre_finding_fk')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users', 'id', 'cre_actor_fk')->restrictOnDelete();
            $table->uuid('upload_id')->unique('cre_upload_unique');
            $table->string('media_type', 10);
            $table->string('mime_type', 50);
            $table->string('original_path', 255)->unique('cre_path_unique');
            $table->string('original_name', 255);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->index(['finding_id', 'id'], 'cre_finding_history_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Customer return findings and evidence are permanent audit history.');
    }
};
