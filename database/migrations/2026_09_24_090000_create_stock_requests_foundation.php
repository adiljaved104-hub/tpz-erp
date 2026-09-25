<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique('sr_reference_uq');
            $table->string('purpose', 30)->index('sr_purpose_idx');
            $table->string('status', 20)->default('pending')->index('sr_status_idx');
            $table->foreignId('order_id')->nullable();
            $table->foreign('order_id', 'sr_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreignId('requested_by_employee_id');
            $table->foreign('requested_by_employee_id', 'sr_requester_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreignId('created_by_user_id');
            $table->foreign('created_by_user_id', 'sr_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->text('reason');
            $table->uuid('idempotency_key')->unique('sr_idempotency_uq');
            $table->timestamps();
            $table->index(['requested_by_employee_id', 'status'], 'sr_requester_status_idx');
        });

        Schema::create('stock_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_request_id');
            $table->foreign('stock_request_id', 'sri_request_fk')->references('id')->on('stock_requests')->restrictOnDelete();
            $table->foreignId('product_inventory_id');
            $table->foreign('product_inventory_id', 'sri_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->string('sku', 190);
            $table->string('product_name', 255);
            $table->string('warehouse_name', 190);
            $table->unsignedInteger('quantity');
            $table->json('proposed_sources');
            $table->unsignedInteger('system_unassigned_quantity')->default(0);
            $table->timestamps();
            $table->unique(['stock_request_id', 'product_inventory_id'], 'sri_request_inventory_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_items');
        Schema::dropIfExists('stock_requests');
    }
};
