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
            DB::statement('CREATE TABLE marketplace_return_removal_items (id INTEGER PRIMARY KEY AUTOINCREMENT, marketplace_return_removal_id INTEGER NOT NULL, customer_return_item_id INTEGER NOT NULL, product_id INTEGER NOT NULL, quantity INTEGER NOT NULL CHECK(quantity > 0), dispatched_quantity INTEGER NOT NULL DEFAULT 0 CHECK(dispatched_quantity >= 0 AND dispatched_quantity <= quantity), received_quantity INTEGER NOT NULL DEFAULT 0 CHECK(received_quantity >= 0 AND received_quantity <= dispatched_quantity), unit_cost NUMERIC NOT NULL CHECK(unit_cost >= 0), source_product_inventory_id INTEGER NOT NULL, destination_product_inventory_id INTEGER NULL, posting_key VARCHAR NOT NULL UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE(marketplace_return_removal_id,customer_return_item_id), FOREIGN KEY(marketplace_return_removal_id) REFERENCES marketplace_return_removals(id) ON DELETE RESTRICT, FOREIGN KEY(customer_return_item_id) REFERENCES customer_return_items(id) ON DELETE RESTRICT, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT, FOREIGN KEY(source_product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT, FOREIGN KEY(destination_product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT)');
            DB::statement('CREATE INDEX marketplace_return_removal_items_return_item_index ON marketplace_return_removal_items(customer_return_item_id)');
            DB::statement('CREATE INDEX marketplace_return_removal_items_product_index ON marketplace_return_removal_items(product_id)');

            return;
        }
        Schema::create('marketplace_return_removal_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('marketplace_return_removal_id');
            $t->foreign('marketplace_return_removal_id', 'mrr_items_removal_fk')->references('id')->on('marketplace_return_removals')->restrictOnDelete();
            $t->foreignId('customer_return_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('dispatched_quantity')->default(0);
            $t->unsignedInteger('received_quantity')->default(0);
            $t->decimal('unit_cost', 15, 4);
            $t->foreignId('source_product_inventory_id');
            $t->foreign('source_product_inventory_id', 'mrr_items_source_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $t->foreignId('destination_product_inventory_id')->nullable();
            $t->foreign('destination_product_inventory_id', 'mrr_items_destination_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $t->uuid('posting_key')->unique();
            $t->timestamps();
            $t->unique(['marketplace_return_removal_id', 'customer_return_item_id'], 'marketplace_removal_item_unique');
            $t->index('customer_return_item_id', 'marketplace_return_removal_items_return_item_index');
            $t->index('product_id', 'marketplace_return_removal_items_product_index');
        });
        DB::statement('ALTER TABLE marketplace_return_removal_items ADD CONSTRAINT marketplace_removal_item_values_check CHECK(quantity > 0 AND dispatched_quantity >= 0 AND dispatched_quantity <= quantity AND received_quantity >= 0 AND received_quantity <= dispatched_quantity AND unit_cost >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('marketplace_return_removal_items') && DB::table('marketplace_return_removal_items')->exists()) {
            throw new RuntimeException('Rollback refused: marketplace removal item history exists.');
        }Schema::dropIfExists('marketplace_return_removal_items');
    }
};
