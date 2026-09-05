<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SEQUENCE_KEY = 'task:2026';

    public function up(): void
    {
        $existing = DB::table('reference_sequences')->where('key', self::SEQUENCE_KEY)->first();
        if ($existing !== null) {
            throw new RuntimeException('Task reference sequence already exists; manual review is required.');
        }

        DB::table('reference_sequences')->insert([
            'key' => self::SEQUENCE_KEY,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('tasks')->exists() || DB::table('task_events')->exists()) {
            throw new RuntimeException('Rollback refused: Task history exists.');
        }

        $sequence = DB::table('reference_sequences')->where('key', self::SEQUENCE_KEY)->first();
        if ($sequence !== null && (int) $sequence->next_value !== 1) {
            throw new RuntimeException('Rollback refused: a Task reference was allocated.');
        }

        DB::table('reference_sequences')->where('key', self::SEQUENCE_KEY)->delete();
    }
};
