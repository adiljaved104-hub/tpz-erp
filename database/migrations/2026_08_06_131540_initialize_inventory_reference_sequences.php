<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['stock_movement', 'opening_stock', 'inventory_reservation'] as $key) {
            DB::table('reference_sequences')->insertOrIgnore([
                'key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (DB::connection()->pretending()) {
                continue;
            }

            $nextValue = DB::table('reference_sequences')->where('key', $key)->value('next_value');

            if (! is_numeric($nextValue) || (int) $nextValue < 1) {
                throw new RuntimeException("Inventory reference sequence {$key} is invalid.");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Inventory references are never decremented or deleted because allocated values may have been consumed.
    }
};
