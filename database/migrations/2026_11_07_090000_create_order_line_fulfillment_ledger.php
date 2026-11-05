<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_line_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('order_item_id');
            $table->string('source_type', 32);
            $table->unsignedInteger('requested_quantity');
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->string('status', 24)->default('pending');
            $table->foreignId('purchase_id')->nullable();
            $table->foreignId('purchase_receipt_id')->nullable();
            $table->foreignId('supplier_id')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('accounting_status', 24)->default('pending');
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['order_id', 'status'], 'olf_order_status_idx');
            $table->index(['order_item_id', 'source_type'], 'olf_item_source_idx');
            $table->foreign('order_id', 'olf_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'olf_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('purchase_id', 'olf_purchase_fk')->references('id')->on('purchases')->nullOnDelete();
            $table->foreign('purchase_receipt_id', 'olf_receipt_fk')->references('id')->on('purchase_receipts')->nullOnDelete();
            $table->foreign('supplier_id', 'olf_supplier_fk')->references('id')->on('suppliers')->nullOnDelete();
        });

        Schema::create('order_line_fulfillment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fulfillment_id');
            $table->string('event_type', 32);
            $table->unsignedInteger('quantity');
            $table->json('evidence')->nullable();
            $table->string('external_reference')->nullable();
            $table->foreignId('recorded_by_user_id');
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['fulfillment_id', 'event_type'], 'olfe_type_idx');
            $table->foreign('fulfillment_id', 'olfe_fulfillment_fk')->references('id')->on('order_line_fulfillments')->restrictOnDelete();
            $table->foreign('recorded_by_user_id', 'olfe_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_fulfillment_events');
        Schema::dropIfExists('order_line_fulfillments');
    }
};
