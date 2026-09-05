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
CREATE TABLE customer_return_inspections (
 id INTEGER PRIMARY KEY AUTOINCREMENT, customer_return_item_id INTEGER NOT NULL,
 quantity INTEGER NOT NULL CHECK(quantity > 0), result VARCHAR NOT NULL CHECK(result IN ('sellable','damaged')),
 inspected_by_user_id INTEGER NOT NULL, inspected_at DATETIME NOT NULL, notes TEXT NULL,
 posting_key VARCHAR NOT NULL UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL,
 FOREIGN KEY(customer_return_item_id) REFERENCES customer_return_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(inspected_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX customer_return_inspections_item_result_index ON customer_return_inspections(customer_return_item_id,result)');

            return;
        }
        Schema::create('customer_return_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('result');
            $table->foreignId('inspected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('inspected_at');
            $table->text('notes')->nullable();
            $table->uuid('posting_key')->unique();
            $table->timestamps();
            $table->index(['customer_return_item_id', 'result']);
        });

        DB::statement("ALTER TABLE customer_return_inspections ADD CONSTRAINT customer_return_inspections_values_check CHECK (quantity > 0 AND result IN ('sellable','damaged'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customer_return_inspections') && DB::table('customer_return_inspections')->exists()) {
            throw new RuntimeException('Rollback refused: Customer Return inspections are immutable.');
        }
        Schema::dropIfExists('customer_return_inspections');
    }
};
