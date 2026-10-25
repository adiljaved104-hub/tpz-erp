<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_return_video_upload_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id')->unique('crvus_upload_unique');
            $table->uuid('request_id');
            $table->foreignId('customer_return_id')->constrained('customer_returns', 'id', 'crvus_return_fk')->restrictOnDelete();
            $table->foreignId('customer_return_item_id')->constrained('customer_return_items', 'id', 'crvus_item_fk')->restrictOnDelete();
            $table->foreignId('employee_user_id')->constrained('users', 'id', 'crvus_user_fk')->restrictOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('customer_return_evidence', 'id', 'crvus_evidence_fk')->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('mime_type', 30);
            $table->unsignedBigInteger('byte_size');
            $table->char('source_md5', 32);
            $table->unsignedSmallInteger('duration_seconds');
            $table->json('finding');
            $table->json('capture');
            $table->json('chunks');
            $table->string('status', 16)->default('open');
            $table->timestamp('expires_at')->index('crvus_expires_idx');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Return evidence upload sessions may link to immutable evidence.');
    }
};
