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
CREATE TABLE purchase_receipt_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_receipt_id INTEGER NOT NULL,
    purchase_item_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity_received INTEGER NOT NULL CHECK (quantity_received > 0),
    accepted_quantity INTEGER NOT NULL DEFAULT 0 CHECK (accepted_quantity >= 0),
    damaged_quantity INTEGER NOT NULL DEFAULT 0 CHECK (damaged_quantity >= 0),
    rejected_quantity INTEGER NOT NULL DEFAULT 0 CHECK (rejected_quantity >= 0),
    inventory_unit_cost NUMERIC NOT NULL CHECK (inventory_unit_cost >= 0),
    posting_key VARCHAR NOT NULL UNIQUE,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT receipt_items_purchase_item_unique UNIQUE (purchase_receipt_id, purchase_item_id),
    CONSTRAINT receipt_items_quantity_check CHECK (accepted_quantity + damaged_quantity + rejected_quantity = quantity_received),
    FOREIGN KEY (purchase_receipt_id) REFERENCES purchase_receipts(id) ON DELETE RESTRICT,
    FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX receipt_items_product_created_index ON purchase_receipt_items (product_id, created_at)');
            DB::statement('CREATE INDEX receipt_items_purchase_item_index ON purchase_receipt_items (purchase_item_id)');

            return;
        }

        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('accepted_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->unsignedInteger('rejected_quantity')->default(0);
            $table->decimal('inventory_unit_cost', 15, 4);
            $table->uuid('posting_key')->unique();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');

            $table->unique(['purchase_receipt_id', 'purchase_item_id'], 'pri_receipt_purchase_item_uq');
            $table->index(['product_id', 'created_at']);
            $table->index('purchase_item_id');
        });

        DB::statement('ALTER TABLE purchase_receipt_items ADD CONSTRAINT purchase_receipt_items_values_check CHECK (quantity_received > 0 AND accepted_quantity >= 0 AND damaged_quantity >= 0 AND rejected_quantity >= 0 AND accepted_quantity + damaged_quantity + rejected_quantity = quantity_received AND inventory_unit_cost >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipt_items');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
