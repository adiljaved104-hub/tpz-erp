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
CREATE TABLE damaged_stock_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reference VARCHAR NOT NULL UNIQUE,
 product_inventory_id INTEGER NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL,
 quantity INTEGER NOT NULL CHECK(quantity > 0),
 source VARCHAR NOT NULL CHECK(source IN ('customer_return','supplier_receipt','internal_warehouse','courier_transit','warranty_service','other_adjustment')),
 source_type VARCHAR NULL, source_id INTEGER NULL, marketplace_platform_id INTEGER NULL,
 customer_return_id INTEGER NULL, customer_return_item_id INTEGER NULL, customer_return_inspection_id INTEGER NULL,
 order_id INTEGER NULL, reason VARCHAR NOT NULL, notes TEXT NULL, occurred_at DATETIME NOT NULL,
 reported_by_user_id INTEGER NOT NULL, status VARCHAR NOT NULL DEFAULT 'damaged' CHECK(status IN ('damaged','resolved')),
 idempotency_key VARCHAR NOT NULL UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL,
 FOREIGN KEY(product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY(marketplace_platform_id) REFERENCES marketplace_platforms(id) ON DELETE RESTRICT,
 FOREIGN KEY(customer_return_id) REFERENCES customer_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY(customer_return_item_id) REFERENCES customer_return_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(customer_return_inspection_id) REFERENCES customer_return_inspections(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(reported_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX damaged_stock_events_inventory_status_index ON damaged_stock_events(product_inventory_id,status)');
            DB::statement('CREATE INDEX damaged_stock_events_source_status_index ON damaged_stock_events(source,status)');
            DB::statement('CREATE INDEX damaged_stock_events_platform_status_index ON damaged_stock_events(marketplace_platform_id,status)');
            DB::statement('CREATE INDEX damaged_stock_events_occurred_at_index ON damaged_stock_events(occurred_at)');
        } else {
            Schema::create('damaged_stock_events', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();
                $table->foreignId('product_inventory_id')->constrained()->restrictOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('quantity');
                $table->string('source');
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->foreignId('marketplace_platform_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('customer_return_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('customer_return_item_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('customer_return_inspection_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->timestamp('occurred_at');
                $table->foreignId('reported_by_user_id')->constrained('users')->restrictOnDelete();
                $table->string('status')->default('damaged');
                $table->uuid('idempotency_key')->unique();
                $table->timestamps();
                $table->index(['product_inventory_id', 'status']);
                $table->index(['source', 'status']);
                $table->index(['marketplace_platform_id', 'status']);
                $table->index('occurred_at');
            });
            DB::statement("ALTER TABLE damaged_stock_events ADD CONSTRAINT damaged_stock_events_values_check CHECK (quantity > 0 AND source IN ('customer_return','supplier_receipt','internal_warehouse','courier_transit','warranty_service','other_adjustment') AND status IN ('damaged','resolved'))");
        }

        DB::table('reference_sequences')->insertOrIgnore([
            'key' => 'damaged_stock:'.now()->year,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('damaged_stock_events') && DB::table('damaged_stock_events')->exists()) {
            throw new RuntimeException('Rollback refused: Damaged Item history is immutable.');
        }

        $key = 'damaged_stock:'.now()->year;
        $sequence = DB::table('reference_sequences')->where('key', $key)->first();
        if ($sequence !== null && (int) $sequence->next_value !== 1) {
            throw new RuntimeException('Rollback refused: Damaged Item references have been allocated.');
        }

        DB::table('reference_sequences')->where('key', $key)->where('next_value', 1)->delete();
        Schema::dropIfExists('damaged_stock_events');
    }
};
