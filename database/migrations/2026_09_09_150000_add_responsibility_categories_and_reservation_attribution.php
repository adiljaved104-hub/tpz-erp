<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsibility_assignment_categories', function (Blueprint $table): void {
            $table->foreignId('assignment_id')->primary()->constrained('responsibility_assignments')->restrictOnDelete();
            $table->foreignId('product_category_id');
            $table->foreign('product_category_id', 'ra_categories_category_fk')->references('id')->on('product_categories')->restrictOnDelete();
            $table->index('product_category_id', 'ra_categories_category_idx');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE responsibility_inventory_consumptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 responsibility_assignment_id INTEGER NOT NULL,
 inventory_reservation_id INTEGER NULL UNIQUE,
 order_fulfillment_item_id INTEGER NULL UNIQUE,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK ((inventory_reservation_id IS NOT NULL AND order_fulfillment_item_id IS NULL) OR (inventory_reservation_id IS NULL AND order_fulfillment_item_id IS NOT NULL)),
 FOREIGN KEY(responsibility_assignment_id) REFERENCES responsibility_assignments(id) ON DELETE RESTRICT,
 FOREIGN KEY(inventory_reservation_id) REFERENCES inventory_reservations(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_fulfillment_item_id) REFERENCES order_fulfillment_items(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX responsibility_consumptions_assignment_idx ON responsibility_inventory_consumptions(responsibility_assignment_id)');
        } else {
            Schema::create('responsibility_inventory_consumptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('responsibility_assignment_id');
                $table->foreignId('inventory_reservation_id')->nullable();
                $table->foreignId('order_fulfillment_item_id')->nullable();
                $table->timestamps();
                $table->foreign('responsibility_assignment_id', 'ric_assignment_fk')->references('id')->on('responsibility_assignments')->restrictOnDelete();
                $table->foreign('inventory_reservation_id', 'ric_reservation_fk')->references('id')->on('inventory_reservations')->restrictOnDelete();
                $table->foreign('order_fulfillment_item_id', 'ric_fulfillment_fk')->references('id')->on('order_fulfillment_items')->restrictOnDelete();
                $table->unique('inventory_reservation_id', 'ric_reservation_unique');
                $table->unique('order_fulfillment_item_id', 'ric_fulfillment_unique');
                $table->index('responsibility_assignment_id', 'responsibility_consumptions_assignment_idx');
            });
            DB::statement('ALTER TABLE responsibility_inventory_consumptions ADD CONSTRAINT responsibility_consumptions_subject_chk CHECK ((inventory_reservation_id IS NOT NULL AND order_fulfillment_item_id IS NULL) OR (inventory_reservation_id IS NULL AND order_fulfillment_item_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('responsibility_assignment_categories')
            && DB::table('responsibility_assignment_categories')->exists()) {
            throw new RuntimeException('Rollback refused: Category Responsibility history exists.');
        }
        if (Schema::hasTable('responsibility_inventory_consumptions')
            && DB::table('responsibility_inventory_consumptions')->exists()) {
            throw new RuntimeException('Rollback refused: Responsibility allocation consumption history exists.');
        }

        Schema::dropIfExists('responsibility_inventory_consumptions');
        Schema::dropIfExists('responsibility_assignment_categories');
    }
};
