<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('marketplace_platform_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warranty_repair_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('safet_claim_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category');
            $table->text('description');
            $table->unsignedInteger('quantity')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolution')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'opened_at']);
            $table->index(['product_id', 'marketplace_platform_id']);
        });
        Schema::create('complaint_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('complaints') && DB::table('complaints')->exists()) {
            throw new RuntimeException('Rollback refused: Complaint history exists.');
        }
        Schema::dropIfExists('complaint_status_events');
        Schema::dropIfExists('complaints');
    }
};
