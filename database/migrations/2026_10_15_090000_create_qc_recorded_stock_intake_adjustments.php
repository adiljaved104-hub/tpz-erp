<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE qc_recorded_stock_intake_adjustments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recorded_stock_intake_id INTEGER NOT NULL,
    quantity_before INTEGER NOT NULL CHECK (quantity_before > 0),
    adjustment_quantity INTEGER NOT NULL CHECK (adjustment_quantity > 0),
    quantity_after INTEGER NOT NULL CHECK (quantity_after >= 0 AND quantity_after = quantity_before - adjustment_quantity),
    unit_cost NUMERIC NOT NULL CHECK (unit_cost >= 0),
    value_reduction NUMERIC NOT NULL CHECK (value_reduction >= 0),
    reason TEXT NOT NULL,
    performed_by_user_id INTEGER NOT NULL,
    idempotency_key VARCHAR(36) NOT NULL UNIQUE,
    stock_movement_id INTEGER NOT NULL UNIQUE,
    created_at DATETIME NULL,
    FOREIGN KEY (recorded_stock_intake_id) REFERENCES qc_recorded_stock_intakes(id) ON DELETE RESTRICT,
    FOREIGN KEY (performed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX qcrsia_intake_created_idx ON qc_recorded_stock_intake_adjustments (recorded_stock_intake_id, created_at)');

            return;
        }

        Schema::create('qc_recorded_stock_intake_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recorded_stock_intake_id')->constrained('qc_recorded_stock_intakes', 'id', 'qcrsia_intake_fk')->restrictOnDelete();
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('adjustment_quantity');
            $table->unsignedInteger('quantity_after');
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('value_reduction', 15, 4);
            $table->text('reason');
            $table->foreignId('performed_by_user_id')->constrained('users', 'id', 'qcrsia_actor_fk')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique('qcrsia_idempotency_uq');
            $table->foreignId('stock_movement_id')->unique('qcrsia_movement_uq')->constrained('stock_movements', 'id', 'qcrsia_movement_fk')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['recorded_stock_intake_id', 'created_at'], 'qcrsia_intake_created_idx');
        });

        DB::statement('ALTER TABLE qc_recorded_stock_intake_adjustments ADD CONSTRAINT qcrsia_counts_check CHECK (quantity_before > 0 AND adjustment_quantity > 0 AND quantity_after >= 0 AND quantity_after = quantity_before - adjustment_quantity AND unit_cost >= 0 AND value_reduction >= 0)');
    }

    public function down(): void
    {
        if (DB::table('qc_recorded_stock_intake_adjustments')->exists()) {
            throw new RuntimeException('Recorded QC intake adjustment history cannot be removed by rollback.');
        }

        Schema::dropIfExists('qc_recorded_stock_intake_adjustments');
    }
};
