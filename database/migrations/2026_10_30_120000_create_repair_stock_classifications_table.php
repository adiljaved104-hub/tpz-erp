<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_stock_classifications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('warranty_repair_id')->unique('rsc_repair_uq');
            $table->foreign('warranty_repair_id', 'rsc_repair_fk')->references('id')->on('warranty_repairs')->restrictOnDelete();
            $table->foreignId('source_product_inventory_id');
            $table->foreign('source_product_inventory_id', 'rsc_source_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreignId('destination_product_inventory_id');
            $table->foreign('destination_product_inventory_id', 'rsc_destination_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreignId('destination_product_id')->constrained('products', indexName: 'rsc_destination_product_fk')->restrictOnDelete();
            $table->string('condition', 24);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 4);
            $table->foreignId('source_movement_id')->unique('rsc_source_movement_uq');
            $table->foreign('source_movement_id', 'rsc_source_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreignId('destination_movement_id')->unique('rsc_destination_movement_uq');
            $table->foreign('destination_movement_id', 'rsc_destination_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('replacement_evidence_reference')->nullable();
            $table->string('allocation_resolution', 40);
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->index(['condition', 'approved_at'], 'rsc_condition_approved_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('repair_stock_classifications') && DB::table('repair_stock_classifications')->exists()) {
            throw new RuntimeException('Rollback refused: repair classification history exists.');
        }

        Schema::dropIfExists('repair_stock_classifications');
    }
};
