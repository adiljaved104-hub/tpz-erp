<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('product_inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('available_before');
            $table->integer('available_delta');
            $table->unsignedInteger('available_after');
            $table->unsignedInteger('damaged_before');
            $table->integer('damaged_delta');
            $table->unsignedInteger('damaged_after');
            $table->text('reason');
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('performed_at');
            $table->foreignId('purchase_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_receipt_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('grn_reference', 32)->nullable();
            $table->decimal('reference_unit_cost', 15, 4)->nullable();
            $table->foreignId('allocation_account_id')->nullable()->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->string('movement_reference', 32);
            $table->uuid('movement_group');
            $table->uuid('batch_key')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['purchase_receipt_id', 'performed_at']);
            $table->index(['performed_by_user_id', 'performed_at']);
            $table->index(['product_id', 'warehouse_id', 'performed_at'], 'ia_product_warehouse_time_idx');
            $table->index(['type', 'performed_at']);
            $table->index('batch_key');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_adjustments') && DB::table('inventory_adjustments')->exists()) {
            throw new RuntimeException('Rollback refused: inventory_adjustments contains posted audit records.');
        }

        Schema::dropIfExists('inventory_adjustments');
    }
};
