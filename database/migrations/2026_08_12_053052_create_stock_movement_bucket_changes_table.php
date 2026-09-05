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
CREATE TABLE stock_movement_bucket_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stock_movement_id INTEGER NOT NULL,
    bucket VARCHAR(50) NOT NULL CHECK (bucket IN ('marketplace_non_sellable', 'qc_pending')),
    quantity_delta INTEGER NOT NULL,
    quantity_before INTEGER NOT NULL CHECK (quantity_before >= 0),
    quantity_after INTEGER NOT NULL CHECK (quantity_after >= 0),
    value_delta NUMERIC NOT NULL,
    value_before NUMERIC NOT NULL CHECK (value_before >= 0),
    value_after NUMERIC NOT NULL CHECK (value_after >= 0),
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT stock_movement_bucket_changes_movement_bucket_unique UNIQUE (stock_movement_id, bucket),
    CONSTRAINT stock_movement_bucket_changes_values_check CHECK (
        quantity_after = quantity_before + quantity_delta
        AND value_after = value_before + value_delta
        AND (quantity_before > 0 OR value_before = 0)
        AND (quantity_after > 0 OR value_after = 0)
    ),
    FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX stock_movement_bucket_changes_bucket_created_at_index ON stock_movement_bucket_changes (bucket, created_at)');

            return;
        }

        Schema::create('stock_movement_bucket_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->string('bucket', 50);
            $table->bigInteger('quantity_delta');
            $table->unsignedBigInteger('quantity_before');
            $table->unsignedBigInteger('quantity_after');
            $table->decimal('value_delta', 15, 4);
            $table->decimal('value_before', 15, 4);
            $table->decimal('value_after', 15, 4);
            $table->timestamps();

            $table->unique(['stock_movement_id', 'bucket']);
            $table->index(['bucket', 'created_at']);
        });

        DB::statement("ALTER TABLE stock_movement_bucket_changes ADD CONSTRAINT stock_movement_bucket_changes_values_check CHECK (bucket IN ('marketplace_non_sellable', 'qc_pending') AND quantity_before >= 0 AND quantity_after >= 0 AND quantity_after = quantity_before + quantity_delta AND value_before >= 0 AND value_after >= 0 AND value_after = value_before + value_delta AND (quantity_before > 0 OR value_before = 0) AND (quantity_after > 0 OR value_after = 0))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('stock_movement_bucket_changes')->exists()) {
            throw new RuntimeException('Rollback refused: Stock Movement custody bucket history exists.');
        }

        Schema::dropIfExists('stock_movement_bucket_changes');
    }
};
