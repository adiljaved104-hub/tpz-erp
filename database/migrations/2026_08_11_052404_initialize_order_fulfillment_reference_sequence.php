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
        $timestamp = now();

        DB::table('reference_sequences')->insertOrIgnore([
            'key' => 'order_fulfillment:'.now()->year,
            'next_value' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Fulfilment references are permanent and are never decremented or reused.
    }
};
