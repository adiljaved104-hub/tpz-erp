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
CREATE TABLE order_fulfillment_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_fulfillment_id INTEGER NOT NULL,
    order_item_id INTEGER NOT NULL UNIQUE,
    product_inventory_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    inventory_unit_cost NUMERIC NOT NULL CHECK (inventory_unit_cost >= 0),
    cogs_total NUMERIC NOT NULL CHECK (cogs_total >= 0),
    posting_key VARCHAR NOT NULL UNIQUE,
    created_at DATETIME NULL,
    FOREIGN KEY (order_fulfillment_id) REFERENCES order_fulfillments(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX order_fulfillment_items_fulfillment_product_index ON order_fulfillment_items (order_fulfillment_id, product_id)');

            return;
        }

        Schema::create('order_fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_fulfillment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('product_inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('inventory_unit_cost', 15, 4);
            $table->decimal('cogs_total', 15, 4);
            $table->uuid('posting_key')->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_fulfillment_id', 'product_id']);
        });

        DB::statement('ALTER TABLE order_fulfillment_items ADD CONSTRAINT order_fulfillment_items_values_check CHECK (quantity > 0 AND inventory_unit_cost >= 0 AND cogs_total >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_fulfillment_items') && DB::table('order_fulfillment_items')->exists()) {
            throw new RuntimeException('Rollback refused: Order Fulfilment Items contain immutable COGS history.');
        }

        Schema::dropIfExists('order_fulfillment_items');
    }
};
