<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_corrections', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32);
            $table->foreignId('purchase_id');
            $table->foreignId('purchase_receipt_id');
            $table->foreignId('purchase_receipt_item_id');
            $table->foreignId('purchase_item_id');
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->unsignedInteger('original_received_quantity');
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('corrected_quantity');
            $table->integer('adjustment_quantity');
            $table->decimal('inventory_unit_cost', 15, 4);
            $table->text('reason');
            $table->foreignId('performed_by_user_id');
            $table->timestamp('corrected_at');
            $table->uuid('idempotency_key');
            $table->uuid('movement_group');
            $table->timestamp('created_at')->nullable();

            $table->unique('reference', 'prc_reference_uq');
            $table->unique('idempotency_key', 'prc_idempotency_uq');
            $table->index(['purchase_receipt_id', 'corrected_at'], 'prc_receipt_time_idx');
            $table->foreign('purchase_id', 'prc_purchase_fk')->references('id')->on('purchases')->restrictOnDelete();
            $table->foreign('purchase_receipt_id', 'prc_receipt_fk')->references('id')->on('purchase_receipts')->restrictOnDelete();
            $table->foreign('purchase_receipt_item_id', 'prc_receipt_item_fk')->references('id')->on('purchase_receipt_items')->restrictOnDelete();
            $table->foreign('purchase_item_id', 'prc_purchase_item_fk')->references('id')->on('purchase_items')->restrictOnDelete();
            $table->foreign('product_id', 'prc_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('warehouse_id', 'prc_warehouse_fk')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('performed_by_user_id', 'prc_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('purchase_receipt_correction_allocation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_correction_id');
            $table->foreignId('purchase_receipt_allocation_line_id');
            $table->foreignId('account_id');
            $table->unsignedInteger('quantity');
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['purchase_receipt_correction_id', 'purchase_receipt_allocation_line_id'],
                'prcal_correction_source_uq',
            );
            $table->foreign('purchase_receipt_correction_id', 'prcal_correction_fk')->references('id')->on('purchase_receipt_corrections')->restrictOnDelete();
            $table->foreign('purchase_receipt_allocation_line_id', 'prcal_source_line_fk')->references('id')->on('purchase_receipt_allocation_lines')->restrictOnDelete();
            $table->foreign('account_id', 'prcal_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_correction_allocation_lines');
        Schema::dropIfExists('purchase_receipt_corrections');
    }
};
