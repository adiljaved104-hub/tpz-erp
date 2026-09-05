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
CREATE TABLE purchase_receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    purchase_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    supplier_delivery_note VARCHAR NULL,
    received_at DATETIME NOT NULL,
    received_by_user_id INTEGER NOT NULL,
    idempotency_key VARCHAR NOT NULL UNIQUE,
    movement_group VARCHAR NOT NULL UNIQUE,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (received_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX purchase_receipts_purchase_received_index ON purchase_receipts (purchase_id, received_at)');
            DB::statement('CREATE INDEX purchase_receipts_warehouse_received_index ON purchase_receipts (warehouse_id, received_at)');

            return;
        }

        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('supplier_delivery_note')->nullable();
            $table->timestamp('received_at');
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->uuid('movement_group')->unique();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');

            $table->index(['purchase_id', 'received_at']);
            $table->index(['warehouse_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('purchase_receipts');
        Schema::dropIfExists('purchase_receipts');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
