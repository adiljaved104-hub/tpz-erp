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
            DB::statement(<<<'SQL'
CREATE TABLE stock_transfer_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stock_transfer_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    product_name VARCHAR NOT NULL,
    sku VARCHAR NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    dispatched_quantity INTEGER NOT NULL DEFAULT 0 CHECK (dispatched_quantity >= 0),
    received_quantity INTEGER NOT NULL DEFAULT 0 CHECK (received_quantity >= 0),
    returned_quantity INTEGER NOT NULL DEFAULT 0 CHECK (returned_quantity >= 0),
    lost_quantity INTEGER NOT NULL DEFAULT 0 CHECK (lost_quantity >= 0),
    source_product_inventory_id INTEGER NULL,
    destination_product_inventory_id INTEGER NULL,
    dispatch_unit_cost NUMERIC NULL CHECK (dispatch_unit_cost IS NULL OR dispatch_unit_cost >= 0),
    posting_key VARCHAR NOT NULL UNIQUE,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT stock_transfer_items_transfer_product_unique UNIQUE (stock_transfer_id, product_id),
    CONSTRAINT stock_transfer_item_quantities_check CHECK (dispatched_quantity <= quantity AND received_quantity + returned_quantity + lost_quantity <= dispatched_quantity),
    FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (source_product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
    FOREIGN KEY (destination_product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX stock_transfer_items_product_transfer_index ON stock_transfer_items (product_id, stock_transfer_id)');

            return;
        }
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('dispatched_quantity')->default(0);
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->unsignedInteger('lost_quantity')->default(0);
            $table->foreignId('source_product_inventory_id')->nullable()->constrained('product_inventories')->restrictOnDelete();
            $table->foreignId('destination_product_inventory_id')->nullable()->constrained('product_inventories')->restrictOnDelete();
            $table->decimal('dispatch_unit_cost', 15, 4)->nullable();
            $table->uuid('posting_key')->unique();
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'product_id']);
            $table->index(['product_id', 'stock_transfer_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_values_check CHECK (quantity > 0 AND dispatched_quantity <= quantity AND received_quantity + returned_quantity + lost_quantity <= dispatched_quantity AND (dispatch_unit_cost IS NULL OR dispatch_unit_cost >= 0))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_transfer_items') && DB::table('stock_transfer_items')->exists()) {
            throw new RuntimeException('Rollback refused: Stock Transfer Items exist.');
        }
        Schema::dropIfExists('stock_transfer_items');
    }
};
