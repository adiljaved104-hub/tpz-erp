<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_marketplace_listings', function (Blueprint $table): void {
            $table->string('stock_available_state', 10)->default('unknown')->after('listing_active_state');
        });
        Schema::table('marketplace_monitor_observations', function (Blueprint $table): void {
            $table->string('stock_available_state', 10)->default('unknown')->after('listing_active_state');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_monitor_observations', fn (Blueprint $table) => $table->dropColumn('stock_available_state'));
        Schema::table('product_marketplace_listings', fn (Blueprint $table) => $table->dropColumn('stock_available_state'));
    }
};
