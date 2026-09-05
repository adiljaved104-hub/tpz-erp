<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = [
        'leave_request:2026',
        'compensatory_off:2026',
        'leave_adjustment:2026',
    ];

    public function up(): void
    {
        $existing = DB::table('reference_sequences')->whereIn('key', self::KEYS)->pluck('key');
        if ($existing->isNotEmpty()) {
            throw new RuntimeException('One or more HR reference sequences already exist; manual review is required: '.$existing->join(', '));
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
        foreach (['leave_requests', 'compensatory_offs', 'leave_entitlement_adjustments'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains HR business history.");
            }
        }

        $changed = DB::table('reference_sequences')->whereIn('key', self::KEYS)->where('next_value', '<>', 1)->pluck('key');
        if ($changed->isNotEmpty()) {
            throw new RuntimeException('Rollback refused: HR references have been allocated: '.$changed->join(', '));
        }

        DB::table('reference_sequences')->whereIn('key', self::KEYS)->delete();
    }
};
