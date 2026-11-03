<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('web_sales_manual_sourcing_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('order_item_id');
            $table->foreignId('product_id');
            $table->string('source_mode', 32)->default('warehouse_receipt');
            $table->string('status', 32)->default('pending_sourcing');
            $table->string('description', 255);
            $table->string('brand', 120)->nullable();
            $table->string('category', 120)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('condition', 32)->nullable();
            $table->decimal('selling_price_snapshot', 15, 2);
            $table->unsignedInteger('quantity');
            $table->foreignId('supplier_id')->nullable();
            $table->foreignId('purchase_id')->nullable();
            $table->foreignId('purchase_receipt_id')->nullable();
            $table->foreign('order_id', 'wsm_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'wsm_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('product_id', 'wsm_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('supplier_id', 'wsm_supplier_fk')->references('id')->on('suppliers')->nullOnDelete();
            $table->foreign('purchase_id', 'wsm_purchase_fk')->references('id')->on('purchases')->nullOnDelete();
            $table->foreign('purchase_receipt_id', 'wsm_receipt_fk')->references('id')->on('purchase_receipts')->nullOnDelete();
            $table->uuid('idempotency_key')->unique('wsm_idempotency_uq');
            $table->timestamp('materialized_at')->nullable();
            $table->timestamp('receipt_confirmed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status'], 'wsm_order_status_idx');
            $table->index(['product_id', 'source_mode'], 'wsm_product_mode_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('web_sales_manual_sourcing_lines') && DB::table('web_sales_manual_sourcing_lines')->exists()) {
            throw new RuntimeException('Rollback refused: manual sourcing history exists.');
        }
        Schema::dropIfExists('web_sales_manual_sourcing_lines');
    }
};
