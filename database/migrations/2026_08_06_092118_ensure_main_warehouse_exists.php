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
        if (DB::connection()->pretending()) {
            DB::table('warehouses')->insert($this->approvedRecord());

            return;
        }

        DB::transaction(function (): void {
            $main = DB::table('warehouses')->where('code', 'MAIN')->first();
            $unexpectedDefaultExists = DB::table('warehouses')
                ->where('code', '!=', 'MAIN')
                ->where('status', true)
                ->where('is_default', true)
                ->exists();

            if ($unexpectedDefaultExists) {
                throw new RuntimeException('Main Warehouse migration aborted: another active default Warehouse already exists.');
            }

            if ($main !== null) {
                $matchesApprovedRecord = $main->name === 'Main Warehouse'
                    && $main->address === null
                    && (bool) $main->status
                    && (bool) $main->is_default;

                if (! $matchesApprovedRecord) {
                    throw new RuntimeException('Main Warehouse migration aborted: code MAIN exists with conflicting approved values.');
                }

                return;
            }

            DB::table('warehouses')->insert($this->approvedRecord());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally retained. This migration never independently deletes Main Warehouse.
    }

    /** @return array<string, mixed> */
    private function approvedRecord(): array
    {
        return [
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'address' => null,
            'status' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
};
