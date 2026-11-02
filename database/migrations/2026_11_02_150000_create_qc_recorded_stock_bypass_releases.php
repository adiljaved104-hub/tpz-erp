<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_recorded_stock_bypass_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recorded_stock_intake_id')->constrained('qc_recorded_stock_intakes')->restrictOnDelete();
            $table->foreignId('product_inventory_id')->constrained('product_inventories')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('qc_quantity_before');
            $table->unsignedInteger('qc_quantity_after');
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('released_value', 18, 4);
            $table->text('reason');
            $table->foreignId('released_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recorded_stock_intake_id', 'created_at'], 'qc_bypass_intake_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('qc_recorded_stock_bypass_releases')
            && \Illuminate\Support\Facades\DB::table('qc_recorded_stock_bypass_releases')->exists()) {
            throw new RuntimeException('Rollback refused: temporary QC bypass history must be retained.');
        }

        Schema::dropIfExists('qc_recorded_stock_bypass_releases');
    }
};
