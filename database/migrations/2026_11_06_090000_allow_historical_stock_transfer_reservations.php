<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_transfer_reservations')) {
            return;
        }

        Schema::table('stock_transfer_reservations', function (Blueprint $table): void {
            $table->dropUnique('str_active_item_uq');
            $table->index(['stock_transfer_id', 'stock_transfer_item_id', 'status'], 'str_item_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_transfer_reservations')) {
            return;
        }

        Schema::table('stock_transfer_reservations', function (Blueprint $table): void {
            $table->dropIndex('str_item_status_idx');
            $table->unique(['stock_transfer_id', 'stock_transfer_item_id', 'status'], 'str_active_item_uq');
        });
    }
};
