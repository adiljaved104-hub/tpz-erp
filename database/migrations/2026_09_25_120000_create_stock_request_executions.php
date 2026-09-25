<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_request_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_request_id');
            $table->foreignId('executed_by_user_id');
            $table->foreignId('order_id')->nullable();
            $table->string('execution_type', 32);
            $table->uuid('idempotency_key');
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->unique('stock_request_id', 'sre_request_uq');
            $table->unique('idempotency_key', 'sre_idempotency_uq');
            $table->foreign('stock_request_id', 'sre_request_fk')->references('id')->on('stock_requests')->restrictOnDelete();
            $table->foreign('executed_by_user_id', 'sre_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('order_id', 'sre_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        Schema::create('stock_request_execution_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_request_execution_id');
            $table->foreignId('stock_request_item_id');
            $table->foreignId('stock_request_source_line_id');
            $table->foreignId('product_inventory_id');
            $table->foreignId('source_account_id');
            $table->foreignId('destination_account_id')->nullable();
            $table->foreignId('inventory_reservation_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('execution_type', 32);
            $table->timestamps();

            $table->unique(['stock_request_source_line_id', 'inventory_reservation_id'], 'srel_src_res_uq');
            $table->foreign('stock_request_execution_id', 'srel_execution_fk')->references('id')->on('stock_request_executions')->restrictOnDelete();
            $table->foreign('stock_request_item_id', 'srel_item_fk')->references('id')->on('stock_request_items')->restrictOnDelete();
            $table->foreign('stock_request_source_line_id', 'srel_source_fk')->references('id')->on('stock_request_source_lines')->restrictOnDelete();
            $table->foreign('product_inventory_id', 'srel_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreign('source_account_id', 'srel_src_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreign('destination_account_id', 'srel_dst_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreign('inventory_reservation_id', 'srel_reservation_fk')->references('id')->on('inventory_reservations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_execution_lines');
        Schema::dropIfExists('stock_request_executions');
    }
};
