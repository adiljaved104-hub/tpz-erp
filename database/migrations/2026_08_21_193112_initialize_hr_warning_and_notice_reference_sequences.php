<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = ['employee_warning:2026', 'hr_notice:2026'];

    public function up(): void
    {
        $existing = DB::table('reference_sequences')->whereIn('key', self::KEYS)->pluck('key');
        if ($existing->isNotEmpty()) {
            throw new RuntimeException('One or more HR Warning/Notice reference sequences already exist; manual review is required: '.$existing->join(', '));
        }

        $now = now();
        DB::table('reference_sequences')->insert(array_map(fn (string $key): array => [
            'key' => $key,
            'next_value' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::KEYS));
    }

    public function down(): void
    {
        foreach (['employee_warnings', 'hr_notices'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains permanent HR history.");
            }
        }
        $changed = DB::table('reference_sequences')->whereIn('key', self::KEYS)->where('next_value', '<>', 1)->pluck('key');
        if ($changed->isNotEmpty()) {
            throw new RuntimeException('Rollback refused: HR Warning/Notice references have been allocated: '.$changed->join(', '));
        }

        DB::table('reference_sequences')->whereIn('key', self::KEYS)->delete();
    }
};
