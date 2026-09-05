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
CREATE TABLE stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    movement_group VARCHAR NOT NULL,
    idempotency_key VARCHAR NULL UNIQUE,
    product_inventory_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    movement_type VARCHAR NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    available_delta INTEGER NOT NULL DEFAULT 0,
    reserved_delta INTEGER NOT NULL DEFAULT 0,
    damaged_delta INTEGER NOT NULL DEFAULT 0,
    available_before INTEGER NOT NULL CHECK (available_before >= 0),
    available_after INTEGER NOT NULL CHECK (available_after >= 0),
    reserved_before INTEGER NOT NULL CHECK (reserved_before >= 0),
    reserved_after INTEGER NOT NULL CHECK (reserved_after >= 0),
    damaged_before INTEGER NOT NULL CHECK (damaged_before >= 0),
    damaged_after INTEGER NOT NULL CHECK (damaged_after >= 0),
    unit_cost NUMERIC NULL CHECK (unit_cost IS NULL OR unit_cost >= 0),
    average_cost_before NUMERIC NULL CHECK (average_cost_before IS NULL OR average_cost_before >= 0),
    average_cost_after NUMERIC NULL CHECK (average_cost_after IS NULL OR average_cost_after >= 0),
    source_type VARCHAR NULL,
    source_id INTEGER NULL,
    reversal_of_id INTEGER NULL UNIQUE,
    reason TEXT NULL,
    actor_user_id INTEGER NOT NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NULL,
    CONSTRAINT stock_movements_before_reserved_check CHECK (reserved_before <= available_before),
    CONSTRAINT stock_movements_after_reserved_check CHECK (reserved_after <= available_after),
    FOREIGN KEY (product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (reversal_of_id) REFERENCES stock_movements(id) ON DELETE RESTRICT,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX stock_movements_movement_group_index ON stock_movements (movement_group)');
            DB::statement('CREATE INDEX stock_movements_movement_type_index ON stock_movements (movement_type)');
            DB::statement('CREATE INDEX stock_movements_occurred_at_index ON stock_movements (occurred_at)');
            DB::statement('CREATE INDEX stock_movements_source_type_source_id_index ON stock_movements (source_type, source_id)');
            DB::statement('CREATE INDEX stock_movements_product_warehouse_occurred_index ON stock_movements (product_id, warehouse_id, occurred_at)');
            DB::statement('CREATE INDEX stock_movements_inventory_occurred_index ON stock_movements (product_inventory_id, occurred_at)');
            DB::statement('CREATE INDEX stock_movements_actor_occurred_index ON stock_movements (actor_user_id, occurred_at)');

            return;
        }

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->uuid('movement_group')->index();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('product_inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('movement_type')->index();
            $table->unsignedInteger('quantity');
            $table->bigInteger('available_delta')->default(0);
            $table->bigInteger('reserved_delta')->default(0);
            $table->bigInteger('damaged_delta')->default(0);
            $table->unsignedInteger('available_before');
            $table->unsignedInteger('available_after');
            $table->unsignedInteger('reserved_before');
            $table->unsignedInteger('reserved_after');
            $table->unsignedInteger('damaged_before');
            $table->unsignedInteger('damaged_after');
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('average_cost_before', 15, 4)->nullable();
            $table->decimal('average_cost_after', 15, 4)->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'warehouse_id', 'occurred_at']);
            $table->index(['product_inventory_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (quantity > 0 AND reserved_before <= available_before AND reserved_after <= available_after AND (unit_cost IS NULL OR unit_cost >= 0) AND (average_cost_before IS NULL OR average_cost_before >= 0) AND (average_cost_after IS NULL OR average_cost_after >= 0))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('stock_movements');
        Schema::dropIfExists('stock_movements');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
