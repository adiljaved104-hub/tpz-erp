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
CREATE TABLE inventory_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    product_inventory_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    status VARCHAR NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'released')),
    reason TEXT NOT NULL,
    idempotency_key VARCHAR NOT NULL UNIQUE,
    release_idempotency_key VARCHAR NULL UNIQUE,
    reserved_by_user_id INTEGER NOT NULL,
    released_by_user_id INTEGER NULL,
    reserved_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY (product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (reserved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (released_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX inventory_reservations_status_index ON inventory_reservations (status)');
            DB::statement('CREATE INDEX inventory_reservations_product_warehouse_status_index ON inventory_reservations (product_id, warehouse_id, status)');

            return;
        }

        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('product_inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status')->default('active')->index();
            $table->text('reason');
            $table->uuid('idempotency_key')->unique();
            $table->uuid('release_idempotency_key')->nullable()->unique();
            $table->foreignId('reserved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'status']);
        });

        DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_values_check CHECK (quantity > 0 AND status IN ('active', 'released'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('inventory_reservations');
        Schema::dropIfExists('inventory_reservations');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
