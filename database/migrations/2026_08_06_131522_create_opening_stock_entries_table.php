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
CREATE TABLE opening_stock_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    product_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    available_quantity INTEGER NOT NULL DEFAULT 0 CHECK (available_quantity >= 0),
    damaged_quantity INTEGER NOT NULL DEFAULT 0 CHECK (damaged_quantity >= 0),
    unit_cost NUMERIC NOT NULL CHECK (unit_cost >= 0),
    reason TEXT NOT NULL,
    idempotency_key VARCHAR NOT NULL UNIQUE,
    movement_group VARCHAR NOT NULL UNIQUE,
    posted_by_user_id INTEGER NOT NULL,
    posted_at DATETIME NOT NULL,
    created_at DATETIME NULL,
    CONSTRAINT opening_stock_product_warehouse_unique UNIQUE (product_id, warehouse_id),
    CONSTRAINT opening_stock_positive_quantity_check CHECK ((available_quantity + damaged_quantity) > 0),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (posted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);

            return;
        }

        Schema::create('opening_stock_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('available_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->text('reason');
            $table->uuid('idempotency_key')->unique();
            $table->uuid('movement_group')->unique();
            $table->foreignId('posted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['product_id', 'warehouse_id']);
        });

        DB::statement('ALTER TABLE opening_stock_entries ADD CONSTRAINT opening_stock_entries_values_check CHECK (available_quantity >= 0 AND damaged_quantity >= 0 AND (available_quantity + damaged_quantity) > 0 AND unit_cost >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('opening_stock_entries');
        Schema::dropIfExists('opening_stock_entries');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
