<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reference_sequences')->insertOrIgnore([
            'key' => 'responsibility_assignment',
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('responsibility_assignments') && DB::table('responsibility_assignments')->exists()) {
            throw new RuntimeException('Rollback refused: Responsibility Assignment references have been used.');
        }

        $sequence = DB::table('reference_sequences')->where('key', 'responsibility_assignment')->first();

        if ($sequence !== null && (int) $sequence->next_value !== 1) {
            throw new RuntimeException('Rollback refused: Responsibility Assignment reference sequence has advanced.');
        }

        DB::table('reference_sequences')->where('key', 'responsibility_assignment')->delete();
    }
};
