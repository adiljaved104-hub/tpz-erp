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
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE product_inventories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    available_quantity INTEGER NOT NULL DEFAULT 0 CHECK (available_quantity >= 0),
    reserved_quantity INTEGER NOT NULL DEFAULT 0 CHECK (reserved_quantity >= 0),
    damaged_quantity INTEGER NOT NULL DEFAULT 0 CHECK (damaged_quantity >= 0),
    average_cost NUMERIC NULL CHECK (average_cost IS NULL OR average_cost >= 0),
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT product_inventories_product_warehouse_unique UNIQUE (product_id, warehouse_id),
    CONSTRAINT product_inventories_reserved_check CHECK (reserved_quantity <= available_quantity),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX product_inventories_warehouse_product_index ON product_inventories (warehouse_id, product_id)');

            return;
        }

        Schema::create('product_inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('available_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->decimal('average_cost', 15, 4)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'product_id']);
        });

        $this->addChecks();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('product_inventories');
        Schema::dropIfExists('product_inventories');
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE product_inventories ADD CONSTRAINT product_inventories_quantities_check CHECK (available_quantity >= 0 AND reserved_quantity >= 0 AND damaged_quantity >= 0 AND reserved_quantity <= available_quantity)');
        DB::statement('ALTER TABLE product_inventories ADD CONSTRAINT product_inventories_average_cost_check CHECK (average_cost IS NULL OR average_cost >= 0)');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
