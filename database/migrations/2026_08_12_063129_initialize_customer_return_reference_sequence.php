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
        DB::table('reference_sequences')->insertOrIgnore([
            'key' => 'customer_return:'.now()->year,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $key = 'customer_return:'.now()->year;
        $sequence = DB::table('reference_sequences')->where('key', $key)->first();
        if ($sequence !== null && (int) $sequence->next_value !== 1) {
            throw new RuntimeException('Rollback refused: Customer Return references have been allocated.');
        }
        DB::table('reference_sequences')->where('key', $key)->where('next_value', 1)->delete();
    }
};
