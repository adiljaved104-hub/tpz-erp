<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_bypass_release_reservation_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bypass_release_id');
            $table->unsignedBigInteger('inventory_reservation_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('allocation_account_id');
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('reserved');
            $table->unsignedBigInteger('reserved_by_user_id');
            $table->timestamp('reserved_at');
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('fulfilled_by_user_id')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->foreign('bypass_release_id', 'qc_bypass_alloc_release_fk')->references('id')->on('qc_recorded_stock_bypass_releases')->restrictOnDelete();
            $table->foreign('inventory_reservation_id', 'qc_bypass_alloc_reservation_fk')->references('id')->on('inventory_reservations')->restrictOnDelete();
            $table->foreign('order_item_id', 'qc_bypass_alloc_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('allocation_account_id', 'qc_bypass_alloc_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreign('reserved_by_user_id', 'qc_bypass_alloc_reserved_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by_user_id', 'qc_bypass_alloc_released_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('fulfilled_by_user_id', 'qc_bypass_alloc_fulfilled_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['bypass_release_id', 'status'], 'qc_bypass_alloc_release_status_idx');
            $table->index(['inventory_reservation_id', 'status'], 'qc_bypass_alloc_reservation_status_idx');
            $table->index(['order_item_id', 'status'], 'qc_bypass_alloc_item_status_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('qc_bypass_release_reservation_allocations')
            && DB::table('qc_bypass_release_reservation_allocations')->exists()) {
            throw new RuntimeException('Rollback refused: QC exception sales provenance must be retained.');
        }

        Schema::dropIfExists('qc_bypass_release_reservation_allocations');
    }
};
