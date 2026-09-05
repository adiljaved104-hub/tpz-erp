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
CREATE TABLE inventory_reservations_with_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_item_id INTEGER NULL UNIQUE,
    reference VARCHAR NOT NULL UNIQUE,
    product_inventory_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    status VARCHAR NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'released', 'fulfilled')),
    reason TEXT NOT NULL,
    idempotency_key VARCHAR NOT NULL UNIQUE,
    release_idempotency_key VARCHAR NULL UNIQUE,
    reserved_by_user_id INTEGER NOT NULL,
    released_by_user_id INTEGER NULL,
    fulfilled_by_user_id INTEGER NULL,
    reserved_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    fulfilled_at DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (reserved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (released_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (fulfilled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement(<<<'SQL'
INSERT INTO inventory_reservations_with_orders (
    id, order_item_id, reference, product_inventory_id, product_id, warehouse_id, quantity, status,
    reason, idempotency_key, release_idempotency_key, reserved_by_user_id, released_by_user_id, fulfilled_by_user_id,
    reserved_at, released_at, fulfilled_at, created_at, updated_at
)
SELECT id, NULL, reference, product_inventory_id, product_id, warehouse_id, quantity, status,
       reason, idempotency_key, release_idempotency_key, reserved_by_user_id, released_by_user_id, NULL,
       reserved_at, released_at, NULL, created_at, updated_at
FROM inventory_reservations
SQL);
            Schema::drop('inventory_reservations');
            Schema::rename('inventory_reservations_with_orders', 'inventory_reservations');
            DB::statement('CREATE INDEX inventory_reservations_status_index ON inventory_reservations (status)');
            DB::statement('CREATE INDEX inventory_reservations_product_warehouse_status_index ON inventory_reservations (product_id, warehouse_id, status)');

            return;
        }

        DB::statement('ALTER TABLE inventory_reservations DROP CHECK inventory_reservations_values_check');

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('id')
                ->unique()
                ->constrained('order_items')
                ->restrictOnDelete();
            $table->foreignId('fulfilled_by_user_id')->nullable()->after('released_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('fulfilled_at')->nullable()->after('released_at');
        });

        DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_values_check CHECK (quantity > 0 AND status IN ('active', 'released', 'fulfilled'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_reservations')->whereNotNull('order_item_id')->exists() || DB::table('inventory_reservations')->where('status', 'fulfilled')->exists()) {
            throw new RuntimeException('Rollback refused: Order-linked Inventory Reservations exist.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE inventory_reservations_without_orders (
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
            DB::statement(<<<'SQL'
INSERT INTO inventory_reservations_without_orders
SELECT id, reference, product_inventory_id, product_id, warehouse_id, quantity, status, reason,
       idempotency_key, release_idempotency_key, reserved_by_user_id, released_by_user_id,
       reserved_at, released_at, created_at, updated_at
FROM inventory_reservations
SQL);
            Schema::drop('inventory_reservations');
            Schema::rename('inventory_reservations_without_orders', 'inventory_reservations');
            DB::statement('CREATE INDEX inventory_reservations_status_index ON inventory_reservations (status)');
            DB::statement('CREATE INDEX inventory_reservations_product_warehouse_status_index ON inventory_reservations (product_id, warehouse_id, status)');

            return;
        }

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfilled_by_user_id');
            $table->dropColumn('fulfilled_at');
            $table->dropConstrainedForeignId('order_item_id');
        });

        DB::statement('ALTER TABLE inventory_reservations DROP CHECK inventory_reservations_values_check');
        DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_values_check CHECK (quantity > 0 AND status IN ('active', 'released'))");
    }
};
