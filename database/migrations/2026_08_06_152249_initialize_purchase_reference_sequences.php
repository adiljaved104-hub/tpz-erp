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
        $year = now()->year;
        $timestamp = now();

        foreach (["purchase:{$year}", "purchase_receipt:{$year}"] as $key) {
            DB::table('reference_sequences')->insertOrIgnore([
                'key' => $key,
                'next_value' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reference sequences are intentionally retained and never decremented.
    }
};
