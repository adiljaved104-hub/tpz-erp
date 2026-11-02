<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_stock_classifications', function (Blueprint $table): void {
            // Nullable only for an independently applied earlier migration; new approvals always populate it.
            $table->char('approval_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('repair_stock_classifications')->exists()) {
            throw new RuntimeException('Rollback refused: repair classification history exists.');
        }

        Schema::table('repair_stock_classifications', function (Blueprint $table): void {
            $table->dropColumn('approval_fingerprint');
        });
    }
};
