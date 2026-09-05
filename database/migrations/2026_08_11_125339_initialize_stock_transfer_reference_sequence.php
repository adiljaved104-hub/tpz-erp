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
        DB::table('reference_sequences')->insertOrIgnore(['key' => 'stock_transfer:'.now()->format('Y'), 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Stock Transfer references are permanent and are never decremented or reused.
    }
};
