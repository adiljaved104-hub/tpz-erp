<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_recorded_stock_bypass_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recorded_stock_intake_id');
            $table->unsignedBigInteger('product_inventory_id');
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('qc_quantity_before');
            $table->unsignedInteger('qc_quantity_after');
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('released_value', 18, 4);
            $table->text('reason');
            $table->unsignedBigInteger('released_by_user_id');
            $table->uuid('idempotency_key')->unique('qc_bypass_release_idempotency_uq');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('recorded_stock_intake_id', 'fk_qc_bypass_release_intake')->references('id')->on('qc_recorded_stock_intakes')->restrictOnDelete();
            $table->foreign('product_inventory_id', 'fk_qc_bypass_release_inventory')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'fk_qc_bypass_release_movement')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('released_by_user_id', 'fk_qc_bypass_release_user')->references('id')->on('users')->restrictOnDelete();
            $table->index(['recorded_stock_intake_id', 'created_at'], 'qc_bypass_intake_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('qc_recorded_stock_bypass_releases')
            && DB::table('qc_recorded_stock_bypass_releases')->exists()) {
            throw new RuntimeException('Rollback refused: temporary QC bypass history must be retained.');
        }

        Schema::dropIfExists('qc_recorded_stock_bypass_releases');
    }
};
