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
CREATE TABLE order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    product_name VARCHAR NOT NULL,
    sku VARCHAR NOT NULL,
    brand_name VARCHAR NULL,
    ordered_quantity INTEGER NOT NULL CHECK (ordered_quantity > 0),
    selling_price NUMERIC NOT NULL CHECK (selling_price >= 0),
    discount_total NUMERIC NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
    vat_rate NUMERIC NOT NULL DEFAULT 0 CHECK (vat_rate >= 0 AND vat_rate <= 100),
    vat_amount NUMERIC NOT NULL DEFAULT 0 CHECK (vat_amount >= 0),
    line_total NUMERIC NOT NULL CHECK (line_total >= 0),
    notes TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT order_items_order_product_unique UNIQUE (order_id, product_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX order_items_product_order_index ON order_items (product_id, order_id)');

            return;
        }

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku');
            $table->string('brand_name')->nullable();
            $table->unsignedInteger('ordered_quantity');
            $table->decimal('selling_price', 15, 2);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('vat_rate', 7, 4)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'product_id']);
            $table->index(['product_id', 'order_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_values_check CHECK (ordered_quantity > 0 AND selling_price >= 0 AND discount_total >= 0 AND vat_rate >= 0 AND vat_rate <= 100 AND vat_amount >= 0 AND line_total >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_items') && DB::table('order_items')->exists()) {
            throw new RuntimeException('Rollback refused: order_items contains business records.');
        }

        Schema::dropIfExists('order_items');
    }
};
