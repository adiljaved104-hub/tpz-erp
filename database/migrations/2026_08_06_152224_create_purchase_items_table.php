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
CREATE TABLE purchase_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    ordered_quantity INTEGER NOT NULL CHECK (ordered_quantity > 0),
    received_quantity INTEGER NOT NULL DEFAULT 0 CHECK (received_quantity >= 0 AND received_quantity <= ordered_quantity),
    rejected_quantity INTEGER NOT NULL DEFAULT 0 CHECK (rejected_quantity >= 0),
    unit_cost NUMERIC NOT NULL CHECK (unit_cost >= 0),
    line_discount_total NUMERIC NOT NULL DEFAULT 0 CHECK (line_discount_total >= 0),
    inventory_unit_cost NUMERIC NOT NULL CHECK (inventory_unit_cost >= 0),
    vat_rate NUMERIC NOT NULL DEFAULT 0 CHECK (vat_rate >= 0 AND vat_rate <= 100),
    vat_amount NUMERIC NOT NULL DEFAULT 0 CHECK (vat_amount >= 0),
    line_subtotal NUMERIC NOT NULL CHECK (line_subtotal >= 0),
    line_net NUMERIC NOT NULL CHECK (line_net >= 0),
    line_total NUMERIC NOT NULL CHECK (line_total >= 0),
    notes TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT purchase_items_product_unique UNIQUE (purchase_id, product_id),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX purchase_items_product_received_index ON purchase_items (product_id, received_quantity)');

            return;
        }

        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('ordered_quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedInteger('rejected_quantity')->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_discount_total', 15, 2)->default(0);
            $table->decimal('inventory_unit_cost', 15, 4);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('line_subtotal', 15, 2);
            $table->decimal('line_net', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['purchase_id', 'product_id']);
            $table->index(['product_id', 'received_quantity']);
        });

        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_values_check CHECK (ordered_quantity > 0 AND received_quantity >= 0 AND received_quantity <= ordered_quantity AND rejected_quantity >= 0 AND unit_cost >= 0 AND line_discount_total >= 0 AND inventory_unit_cost >= 0 AND vat_rate BETWEEN 0 AND 100 AND vat_amount >= 0 AND line_subtotal >= 0 AND line_net >= 0 AND line_total >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('purchase_items');
        Schema::dropIfExists('purchase_items');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
