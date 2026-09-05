<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE marketplace_platforms ADD COLUMN return_handling_mode VARCHAR(100) NULL CHECK (return_handling_mode IS NULL OR return_handling_mode IN ('marketplace_restock_or_hold_non_sellable', 'marketplace_restock_or_auto_return', 'company_direct_return'))");
            DB::statement('ALTER TABLE marketplace_platforms ADD COLUMN default_return_receiving_warehouse_id INTEGER NULL REFERENCES warehouses(id) ON DELETE RESTRICT');
            DB::statement('CREATE INDEX marketplace_platforms_return_handling_mode_index ON marketplace_platforms (return_handling_mode)');
            DB::statement('CREATE INDEX marketplace_platforms_default_return_warehouse_index ON marketplace_platforms (default_return_receiving_warehouse_id)');

            return;
        }

        Schema::table('marketplace_platforms', function (Blueprint $table): void {
            $table->string('return_handling_mode', 100)->nullable()->index();
            $table->foreignId('default_return_receiving_warehouse_id')->nullable();
            $table->foreign('default_return_receiving_warehouse_id', 'platform_return_warehouse_fk')
                ->references('id')->on('warehouses')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE marketplace_platforms ADD CONSTRAINT marketplace_platforms_return_mode_check CHECK (return_handling_mode IS NULL OR return_handling_mode IN ('marketplace_restock_or_hold_non_sellable', 'marketplace_restock_or_auto_return', 'company_direct_return'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('marketplace_platforms')->whereNotNull('return_handling_mode')->orWhereNotNull('default_return_receiving_warehouse_id')->exists()) {
            throw new RuntimeException('Rollback refused: Marketplace Return configuration is in use.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX marketplace_platforms_return_handling_mode_index');
            DB::statement('DROP INDEX marketplace_platforms_default_return_warehouse_index');
            DB::statement('ALTER TABLE marketplace_platforms DROP COLUMN default_return_receiving_warehouse_id');
            DB::statement('ALTER TABLE marketplace_platforms DROP COLUMN return_handling_mode');

            return;
        }

        DB::statement('ALTER TABLE marketplace_platforms DROP CHECK marketplace_platforms_return_mode_check');
        Schema::table('marketplace_platforms', function (Blueprint $table): void {
            $table->dropForeign('platform_return_warehouse_fk');
            $table->dropColumn(['return_handling_mode', 'default_return_receiving_warehouse_id']);
        });
    }
};
