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
CREATE TABLE qc_recorded_stock_intakes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_receipt_item_id INTEGER NOT NULL UNIQUE,
    product_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    allocation_account_id INTEGER NOT NULL,
    allocation_method VARCHAR(30) NOT NULL,
    accepted_quantity INTEGER NOT NULL CHECK (accepted_quantity >= 0),
    started_quantity INTEGER NOT NULL DEFAULT 0 CHECK (started_quantity >= 0 AND started_quantity <= accepted_quantity),
    released_quantity INTEGER NOT NULL DEFAULT 0 CHECK (released_quantity >= 0 AND released_quantity <= started_quantity),
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY (purchase_receipt_item_id) REFERENCES purchase_receipt_items(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (allocation_account_id) REFERENCES inventory_allocation_accounts(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX qcrsi_product_location_idx ON qc_recorded_stock_intakes (product_id, warehouse_id)');
        } else {
            Schema::create('qc_recorded_stock_intakes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('purchase_receipt_item_id')->unique('qcrsi_receipt_item_uq');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('allocation_account_id');
                $table->string('allocation_method', 30);
                $table->unsignedInteger('accepted_quantity');
                $table->unsignedInteger('started_quantity')->default(0);
                $table->unsignedInteger('released_quantity')->default(0);
                $table->timestamps();
                $table->foreign('purchase_receipt_item_id', 'qcrsi_receipt_item_fk')->references('id')->on('purchase_receipt_items')->restrictOnDelete();
                $table->foreign('product_id', 'qcrsi_product_fk')->references('id')->on('products')->restrictOnDelete();
                $table->foreign('warehouse_id', 'qcrsi_warehouse_fk')->references('id')->on('warehouses')->restrictOnDelete();
                $table->foreign('allocation_account_id', 'qcrsi_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
                $table->index(['product_id', 'warehouse_id'], 'qcrsi_product_location_idx');
            });
            DB::statement('ALTER TABLE qc_recorded_stock_intakes ADD CONSTRAINT qcrsi_counts_check CHECK (accepted_quantity >= 0 AND started_quantity <= accepted_quantity AND released_quantity <= started_quantity)');
        }

        Schema::table('qc_inspections', function (Blueprint $table): void {
            $table->unsignedBigInteger('recorded_stock_intake_id')->nullable();
            $table->string('qc_source', 32)->nullable();
            $table->foreign('recorded_stock_intake_id', 'qci_recorded_intake_fk')->references('id')->on('qc_recorded_stock_intakes')->restrictOnDelete();
            $table->index('recorded_stock_intake_id', 'qci_recorded_intake_idx');
        });

        Schema::create('qc_recorded_stock_intake_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recorded_stock_intake_id');
            $table->unsignedBigInteger('qc_device_id')->unique('qcrsir_device_uq');
            $table->unsignedBigInteger('qc_inspection_id');
            $table->unsignedBigInteger('qc_certificate_id')->unique('qcrsir_certificate_uq');
            $table->unsignedBigInteger('stock_movement_id')->unique('qcrsir_movement_uq');
            $table->unsignedBigInteger('released_by_user_id');
            $table->unsignedInteger('certificate_version');
            $table->timestamp('released_at');
            $table->timestamps();
            $table->foreign('recorded_stock_intake_id', 'qcrsir_intake_fk')->references('id')->on('qc_recorded_stock_intakes')->restrictOnDelete();
            $table->foreign('qc_device_id', 'qcrsir_device_fk')->references('id')->on('qc_devices')->restrictOnDelete();
            $table->foreign('qc_inspection_id', 'qcrsir_inspection_fk')->references('id')->on('qc_inspections')->restrictOnDelete();
            $table->foreign('qc_certificate_id', 'qcrsir_certificate_fk')->references('id')->on('qc_certificates')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'qcrsir_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('released_by_user_id', 'qcrsir_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['recorded_stock_intake_id', 'released_at'], 'qcrsir_intake_release_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('qc_recorded_stock_intake_releases')->exists()
            || DB::table('qc_inspections')->whereNotNull('recorded_stock_intake_id')->exists()
            || DB::table('qc_recorded_stock_intakes')->exists()
        ) {
            throw new RuntimeException('Recorded QC intake history cannot be removed by rollback.');
        }

        Schema::dropIfExists('qc_recorded_stock_intake_releases');
        Schema::table('qc_inspections', function (Blueprint $table): void {
            $table->dropForeign('qci_recorded_intake_fk');
            $table->dropIndex('qci_recorded_intake_idx');
            $table->dropColumn(['recorded_stock_intake_id', 'qc_source']);
        });
        Schema::dropIfExists('qc_recorded_stock_intakes');
    }
};
