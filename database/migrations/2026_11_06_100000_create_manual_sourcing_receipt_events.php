<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('web_sales_manual_sourcing_receipt_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('line_id');
            $table->foreignId('purchase_receipt_id');
            $table->unsignedInteger('quantity');
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['line_id', 'purchase_receipt_id'], 'wsm_receipt_line_grn_uq');
            $table->index(['line_id', 'confirmed_at'], 'wsm_receipt_line_time_idx');
            $table->foreign('line_id', 'wsmse_line_fk')->references('id')->on('web_sales_manual_sourcing_lines')->restrictOnDelete();
            $table->foreign('purchase_receipt_id', 'wsmse_receipt_fk')->references('id')->on('purchase_receipts')->restrictOnDelete();
        });

        Schema::create('web_sales_supplier_direct_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('line_id');
            $table->unsignedInteger('quantity');
            $table->string('supplier_reference', 255)->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('confirmed_by_user_id');
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->index(['line_id', 'confirmed_at'], 'wsm_direct_line_time_idx');
            $table->foreign('line_id', 'wsdd_line_fk')->references('id')->on('web_sales_manual_sourcing_lines')->restrictOnDelete();
            $table->foreign('confirmed_by_user_id', 'wsdd_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_sales_supplier_direct_deliveries');
        Schema::dropIfExists('web_sales_manual_sourcing_receipt_events');
    }
};
