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
            DB::statement('ALTER TABLE product_inventories ADD COLUMN marketplace_non_sellable_quantity INTEGER NOT NULL DEFAULT 0 CHECK (marketplace_non_sellable_quantity >= 0)');
            DB::statement('ALTER TABLE product_inventories ADD COLUMN marketplace_non_sellable_value NUMERIC NOT NULL DEFAULT 0.0000 CHECK (marketplace_non_sellable_value >= 0 AND (marketplace_non_sellable_quantity > 0 OR marketplace_non_sellable_value = 0))');
            DB::statement('ALTER TABLE product_inventories ADD COLUMN qc_pending_quantity INTEGER NOT NULL DEFAULT 0 CHECK (qc_pending_quantity >= 0)');
            DB::statement('ALTER TABLE product_inventories ADD COLUMN qc_pending_value NUMERIC NOT NULL DEFAULT 0.0000 CHECK (qc_pending_value >= 0 AND (qc_pending_quantity > 0 OR qc_pending_value = 0))');

            return;
        }

        Schema::table('product_inventories', function (Blueprint $table): void {
            $table->unsignedInteger('marketplace_non_sellable_quantity')->default(0);
            $table->decimal('marketplace_non_sellable_value', 15, 4)->default(0);
            $table->unsignedInteger('qc_pending_quantity')->default(0);
            $table->decimal('qc_pending_value', 15, 4)->default(0);
        });
        DB::statement('ALTER TABLE product_inventories ADD CONSTRAINT product_inventories_return_custody_check CHECK (marketplace_non_sellable_quantity >= 0 AND marketplace_non_sellable_value >= 0 AND qc_pending_quantity >= 0 AND qc_pending_value >= 0 AND (marketplace_non_sellable_quantity > 0 OR marketplace_non_sellable_value = 0) AND (qc_pending_quantity > 0 OR qc_pending_value = 0))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('product_inventories')->where('marketplace_non_sellable_quantity', '!=', 0)
            ->orWhere('marketplace_non_sellable_value', '!=', 0)
            ->orWhere('qc_pending_quantity', '!=', 0)
            ->orWhere('qc_pending_value', '!=', 0)
            ->exists()) {
            throw new RuntimeException('Rollback refused: Product Inventory Return custody balances are in use.');
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['qc_pending_value', 'qc_pending_quantity', 'marketplace_non_sellable_value', 'marketplace_non_sellable_quantity'] as $column) {
                DB::statement("ALTER TABLE product_inventories DROP COLUMN {$column}");
            }

            return;
        }

        DB::statement('ALTER TABLE product_inventories DROP CHECK product_inventories_return_custody_check');
        Schema::table('product_inventories', function (Blueprint $table): void {
            $table->dropColumn(['marketplace_non_sellable_quantity', 'marketplace_non_sellable_value', 'qc_pending_quantity', 'qc_pending_value']);
        });
    }
};
