<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            // Native additive DDL preserves existing CHECKs and SQLite automatic indexes.
            DB::statement('ALTER TABLE quotations ADD COLUMN warehouse_id INTEGER NULL REFERENCES warehouses(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE order_items ADD COLUMN quotation_item_id INTEGER NULL REFERENCES quotation_items(id) ON DELETE RESTRICT');
            DB::statement('CREATE UNIQUE INDEX order_items_quotation_item_uq ON order_items(quotation_item_id)');
        } else {
            Schema::table('quotations', function (Blueprint $table): void {
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            });
            Schema::table('order_items', function (Blueprint $table): void {
                $table->foreignId('quotation_item_id')->nullable()->unique('order_items_quotation_item_uq')->constrained('quotation_items')->restrictOnDelete();
            });
        }
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $fk = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
        DB::statement("CREATE TABLE quotation_item_sourcing_instructions (
            id {$id},
            quotation_item_id {$fk} NOT NULL UNIQUE,
            planned_source_quantity_snapshot INTEGER NOT NULL CHECK (planned_source_quantity_snapshot >= 0),
            purchase_unit_cost DECIMAL(15,4) NOT NULL CHECK (purchase_unit_cost > 0),
            source_note VARCHAR(1000) NULL,
            configured_by_user_id {$fk} NOT NULL,
            created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
            FOREIGN KEY (quotation_item_id) REFERENCES quotation_items(id) ON DELETE RESTRICT,
            FOREIGN KEY (configured_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )");
        DB::statement("CREATE TABLE quotation_sourcing_postings (
            id {$id},
            sourcing_instruction_id {$fk} NOT NULL UNIQUE,
            quotation_item_id {$fk} NOT NULL UNIQUE,
            order_item_id {$fk} NOT NULL UNIQUE,
            product_inventory_id {$fk} NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            purchase_unit_cost DECIMAL(15,4) NOT NULL CHECK (purchase_unit_cost > 0),
            total_cost DECIMAL(19,4) NOT NULL CHECK (total_cost > 0),
            source_note VARCHAR(1000) NULL,
            idempotency_key VARCHAR(36) NOT NULL UNIQUE,
            posted_by_user_id {$fk} NOT NULL,
            posted_at TIMESTAMP NOT NULL, created_at TIMESTAMP NULL,
            FOREIGN KEY (sourcing_instruction_id) REFERENCES quotation_item_sourcing_instructions(id) ON DELETE RESTRICT,
            FOREIGN KEY (quotation_item_id) REFERENCES quotation_items(id) ON DELETE RESTRICT,
            FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
            FOREIGN KEY (product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
            FOREIGN KEY (posted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )");
        Schema::table('quotation_sourcing_postings', fn (Blueprint $table) => $table->index(['product_inventory_id', 'posted_at'], 'quotation_sourcing_inventory_date_idx'));
    }

    public function down(): void
    {
        if (DB::table('quotation_item_sourcing_instructions')->exists()
            || DB::table('quotation_sourcing_postings')->exists()
            || DB::table('order_items')->whereNotNull('quotation_item_id')->exists()
            || DB::table('quotations')->whereNotNull('warehouse_id')->exists()) {
            throw new RuntimeException('Rollback refused: quotation sourcing or warehouse records exist.');
        }
        Schema::drop('quotation_sourcing_postings');
        Schema::drop('quotation_item_sourcing_instructions');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX order_items_quotation_item_uq');
            DB::statement('ALTER TABLE order_items DROP COLUMN quotation_item_id');
            DB::statement('ALTER TABLE quotations DROP COLUMN warehouse_id');

            return;
        }
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['quotation_item_id']);
            $table->dropUnique('order_items_quotation_item_uq');
            $table->dropColumn('quotation_item_id');
        });
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
