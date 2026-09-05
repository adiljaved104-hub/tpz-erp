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
        DB::table('reference_sequences')->insertOrIgnore(['key' => 'marketplace_return_removal:'.now()->year, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $key = 'marketplace_return_removal:'.now()->year;
        $row = DB::table('reference_sequences')->where('key', $key)->first();
        if ($row && (int) $row->next_value !== 1) {
            throw new RuntimeException('Rollback refused: Marketplace Removal references were allocated.');
        }DB::table('reference_sequences')->where('key', $key)->where('next_value', 1)->delete();
    }
};
