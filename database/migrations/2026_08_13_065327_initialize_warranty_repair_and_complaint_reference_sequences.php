<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['warranty_repair:'.now()->year, 'complaint:'.now()->year] as $key) {
            DB::table('reference_sequences')->insertOrIgnore(['key' => $key, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['warranty_repair:'.now()->year, 'complaint:'.now()->year] as $key) {
            $row = DB::table('reference_sequences')->where('key', $key)->first();
            if ($row && (int) $row->next_value !== 1) {
                throw new RuntimeException('Rollback refused: a service reference was allocated.');
            }
            DB::table('reference_sequences')->where('key', $key)->delete();
        }
    }
};
