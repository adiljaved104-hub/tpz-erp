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
            DB::statement('ALTER TABLE customer_return_items ADD COLUMN company_receiving_warehouse_id INTEGER NULL REFERENCES warehouses(id) ON DELETE RESTRICT');
            DB::statement('CREATE INDEX customer_return_items_company_receiving_warehouse_id_index ON customer_return_items(company_receiving_warehouse_id)');

            return;
        }
        Schema::table('customer_return_items', function (Blueprint $table) {
            $table->foreignId('company_receiving_warehouse_id')->nullable();
            $table->foreign('company_receiving_warehouse_id', 'return_items_receiving_warehouse_fk')->references('id')->on('warehouses')->restrictOnDelete();
            $table->index('company_receiving_warehouse_id', 'return_items_receiving_warehouse_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_return_items')->whereNotNull('company_receiving_warehouse_id')->exists()) {
            throw new RuntimeException('Rollback refused: marketplace company receipt linkage is in use.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX customer_return_items_company_receiving_warehouse_id_index');
            DB::statement('ALTER TABLE customer_return_items DROP COLUMN company_receiving_warehouse_id');

            return;
        }
        Schema::table('customer_return_items', function (Blueprint $table) {
            $table->dropForeign('return_items_receiving_warehouse_fk');
            $table->dropIndex('return_items_receiving_warehouse_idx');
            $table->dropColumn('company_receiving_warehouse_id');
        });
    }
};
