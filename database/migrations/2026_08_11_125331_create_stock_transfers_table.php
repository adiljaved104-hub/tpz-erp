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
CREATE TABLE stock_transfers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    source_warehouse_id INTEGER NOT NULL,
    destination_warehouse_id INTEGER NOT NULL,
    status VARCHAR NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'dispatched', 'received', 'cancelled', 'returned')),
    transfer_date DATE NOT NULL,
    handled_by_employee_id INTEGER NULL,
    created_by_user_id INTEGER NOT NULL,
    dispatched_by_user_id INTEGER NULL,
    received_by_user_id INTEGER NULL,
    cancelled_by_user_id INTEGER NULL,
    returned_by_user_id INTEGER NULL,
    idempotency_key VARCHAR NOT NULL UNIQUE,
    dispatch_idempotency_key VARCHAR NULL UNIQUE,
    receive_idempotency_key VARCHAR NULL UNIQUE,
    cancellation_idempotency_key VARCHAR NULL UNIQUE,
    return_idempotency_key VARCHAR NULL UNIQUE,
    dispatched_at DATETIME NULL,
    received_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    returned_at DATETIME NULL,
    cancellation_reason TEXT NULL,
    return_reason TEXT NULL,
    notes TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT stock_transfers_locations_differ CHECK (source_warehouse_id <> destination_warehouse_id),
    FOREIGN KEY (source_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (destination_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (handled_by_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (dispatched_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (received_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (returned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX stock_transfers_status_date_index ON stock_transfers (status, transfer_date)');
            DB::statement('CREATE INDEX stock_transfers_source_status_index ON stock_transfers (source_warehouse_id, status)');
            DB::statement('CREATE INDEX stock_transfers_destination_status_index ON stock_transfers (destination_warehouse_id, status)');
            DB::statement('CREATE INDEX stock_transfers_handler_status_index ON stock_transfers (handled_by_employee_id, status)');

            return;
        }

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->date('transfer_date');
            $table->foreignId('handled_by_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            foreach (['created_by_user_id', 'dispatched_by_user_id', 'received_by_user_id', 'cancelled_by_user_id', 'returned_by_user_id'] as $column) {
                $table->foreignId($column)->nullable($column !== 'created_by_user_id')->constrained('users')->restrictOnDelete();
            }
            $table->uuid('idempotency_key')->unique();
            foreach (['dispatch_idempotency_key', 'receive_idempotency_key', 'cancellation_idempotency_key', 'return_idempotency_key'] as $column) {
                $table->uuid($column)->nullable()->unique();
            }
            foreach (['dispatched_at', 'received_at', 'cancelled_at', 'returned_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->text('cancellation_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'transfer_date']);
            $table->index(['source_warehouse_id', 'status']);
            $table->index(['destination_warehouse_id', 'status']);
            $table->index(['handled_by_employee_id', 'status']);
        });
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_values_check CHECK (source_warehouse_id <> destination_warehouse_id AND status IN ('draft', 'dispatched', 'received', 'cancelled', 'returned'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_transfers') && DB::table('stock_transfers')->exists()) {
            throw new RuntimeException('Rollback refused: Stock Transfers exist.');
        }
        Schema::dropIfExists('stock_transfers');
    }
};
