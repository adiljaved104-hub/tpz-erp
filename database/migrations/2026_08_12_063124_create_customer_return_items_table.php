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
CREATE TABLE customer_return_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, customer_return_id INTEGER NOT NULL, order_item_id INTEGER NOT NULL,
 order_fulfillment_item_id INTEGER NOT NULL, product_id INTEGER NOT NULL, sku_snapshot VARCHAR NOT NULL,
 product_name_snapshot VARCHAR NOT NULL, fulfilled_quantity_snapshot INTEGER NOT NULL CHECK(fulfilled_quantity_snapshot > 0),
 return_quantity INTEGER NOT NULL CHECK(return_quantity > 0 AND return_quantity <= fulfilled_quantity_snapshot),
 inventory_unit_cost NUMERIC NOT NULL CHECK(inventory_unit_cost >= 0), return_reason VARCHAR NOT NULL CHECK(return_reason IN ('customer_changed_mind','wrong_item','defective','damaged_by_customer','not_as_described','other')),
 reason_notes TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
 CONSTRAINT customer_return_items_return_fulfillment_unique UNIQUE(customer_return_id,order_fulfillment_item_id),
 FOREIGN KEY(customer_return_id) REFERENCES customer_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_fulfillment_item_id) REFERENCES order_fulfillment_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX customer_return_items_fulfillment_return_index ON customer_return_items(order_fulfillment_item_id,customer_return_id)');

            return;
        }
        Schema::create('customer_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_fulfillment_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku_snapshot');
            $table->string('product_name_snapshot');
            $table->unsignedInteger('fulfilled_quantity_snapshot');
            $table->unsignedInteger('return_quantity');
            $table->decimal('inventory_unit_cost', 15, 4);
            $table->string('return_reason');
            $table->text('reason_notes')->nullable();
            $table->timestamps();

            $table->unique(['customer_return_id', 'order_fulfillment_item_id'], 'customer_return_items_return_fulfillment_unique');
            $table->index(['order_fulfillment_item_id', 'customer_return_id'], 'customer_return_items_fulfillment_return_index');
        });

        DB::statement("ALTER TABLE customer_return_items ADD CONSTRAINT customer_return_items_values_check CHECK (fulfilled_quantity_snapshot > 0 AND return_quantity > 0 AND return_quantity <= fulfilled_quantity_snapshot AND inventory_unit_cost >= 0 AND return_reason IN ('customer_changed_mind','wrong_item','defective','damaged_by_customer','not_as_described','other'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customer_return_items') && DB::table('customer_return_items')->exists()) {
            throw new RuntimeException('Rollback refused: Customer Return Items contain immutable history.');
        }
        Schema::dropIfExists('customer_return_items');
    }
};
