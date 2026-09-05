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
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    source VARCHAR NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'marketplace', 'api', 'automation')),
    status VARCHAR NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'pending_review', 'confirmed', 'reserved', 'processing', 'fulfilled', 'cancelled')),
    warehouse_id INTEGER NOT NULL,
    marketplace_platform_id INTEGER NULL,
    external_order_number VARCHAR NULL,
    external_identity_hash VARCHAR(64) NULL UNIQUE,
    order_date DATE NOT NULL,
    subtotal NUMERIC NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    discount_total NUMERIC NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
    vat_total NUMERIC NOT NULL DEFAULT 0 CHECK (vat_total >= 0),
    grand_total NUMERIC NOT NULL DEFAULT 0 CHECK (grand_total >= 0),
    handled_by_employee_id INTEGER NULL,
    notes TEXT NULL,
    idempotency_key VARCHAR NOT NULL UNIQUE,
    created_by_user_id INTEGER NOT NULL,
    reserved_by_user_id INTEGER NULL,
    cancelled_by_user_id INTEGER NULL,
    cancellation_idempotency_key VARCHAR NULL UNIQUE,
    reserved_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (marketplace_platform_id) REFERENCES marketplace_platforms(id) ON DELETE RESTRICT,
    FOREIGN KEY (handled_by_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (reserved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX orders_source_index ON orders (source)');
            DB::statement('CREATE INDEX orders_status_index ON orders (status)');
            DB::statement('CREATE INDEX orders_platform_external_index ON orders (marketplace_platform_id, external_order_number)');
            DB::statement('CREATE INDEX orders_warehouse_status_index ON orders (warehouse_id, status)');
            DB::statement('CREATE INDEX orders_handler_status_index ON orders (handled_by_employee_id, status)');

            return;
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('source')->default('manual')->index();
            $table->string('status')->default('draft')->index();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketplace_platform_id')->nullable()->constrained('marketplace_platforms')->restrictOnDelete();
            $table->string('external_order_number')->nullable();
            $table->string('external_identity_hash', 64)->nullable()->unique();
            $table->date('order_date');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('vat_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->foreignId('handled_by_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reserved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('cancellation_idempotency_key')->nullable()->unique();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['marketplace_platform_id', 'external_order_number']);
            $table->index(['warehouse_id', 'status']);
            $table->index(['handled_by_employee_id', 'status']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_values_check CHECK (source IN ('manual', 'marketplace', 'api', 'automation') AND status IN ('draft', 'pending_review', 'confirmed', 'reserved', 'processing', 'fulfilled', 'cancelled') AND subtotal >= 0 AND discount_total >= 0 AND vat_total >= 0 AND grand_total >= 0)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders') && DB::table('orders')->exists()) {
            throw new RuntimeException('Rollback refused: orders contains business records.');
        }

        Schema::dropIfExists('orders');
    }
};
