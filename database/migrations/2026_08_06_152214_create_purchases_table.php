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
CREATE TABLE purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    supplier_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    supplier_invoice_number VARCHAR NULL,
    supplier_invoice_number_normalized VARCHAR NULL,
    supplier_invoice_date DATE NULL,
    purchase_date DATE NOT NULL,
    expected_delivery_date DATE NULL,
    currency VARCHAR NOT NULL DEFAULT 'AED' CHECK (currency = 'AED'),
    status VARCHAR NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','approved','partially_received','fully_received','closed','cancelled')),
    subtotal NUMERIC NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    discount_total NUMERIC NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
    net_before_vat NUMERIC NOT NULL DEFAULT 0 CHECK (net_before_vat >= 0),
    shipping_total NUMERIC NOT NULL DEFAULT 0 CHECK (shipping_total >= 0),
    shipping_vat_rate NUMERIC NOT NULL DEFAULT 0 CHECK (shipping_vat_rate >= 0 AND shipping_vat_rate <= 100),
    shipping_vat_amount NUMERIC NOT NULL DEFAULT 0 CHECK (shipping_vat_amount >= 0),
    other_charges_total NUMERIC NOT NULL DEFAULT 0 CHECK (other_charges_total >= 0),
    other_charges_vat_rate NUMERIC NOT NULL DEFAULT 0 CHECK (other_charges_vat_rate >= 0 AND other_charges_vat_rate <= 100),
    other_charges_vat_amount NUMERIC NOT NULL DEFAULT 0 CHECK (other_charges_vat_amount >= 0),
    vat_total NUMERIC NOT NULL DEFAULT 0 CHECK (vat_total >= 0),
    grand_total NUMERIC NOT NULL DEFAULT 0 CHECK (grand_total >= 0),
    notes TEXT NULL,
    created_by_user_id INTEGER NOT NULL,
    approved_by_user_id INTEGER NULL,
    approved_at DATETIME NULL,
    approval_reason TEXT NULL,
    self_approved INTEGER NOT NULL DEFAULT 0 CHECK (self_approved IN (0,1)),
    cancelled_by_user_id INTEGER NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason TEXT NULL,
    closed_by_user_id INTEGER NULL,
    closed_at DATETIME NULL,
    closure_reason TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT purchases_supplier_invoice_unique UNIQUE (supplier_id, supplier_invoice_number_normalized),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX purchases_status_expected_index ON purchases (status, expected_delivery_date)');
            DB::statement('CREATE INDEX purchases_supplier_date_index ON purchases (supplier_id, purchase_date)');
            DB::statement('CREATE INDEX purchases_warehouse_status_index ON purchases (warehouse_id, status)');
            DB::statement('CREATE INDEX purchases_creator_status_index ON purchases (created_by_user_id, status)');

            return;
        }

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_number')->nullable();
            $table->string('supplier_invoice_number_normalized')->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('purchase_date');
            $table->date('expected_delivery_date')->nullable();
            $table->char('currency', 3)->default('AED');
            $table->string('status')->default('draft');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('net_before_vat', 15, 2)->default(0);
            $table->decimal('shipping_total', 15, 2)->default(0);
            $table->decimal('shipping_vat_rate', 5, 2)->default(0);
            $table->decimal('shipping_vat_amount', 15, 2)->default(0);
            $table->decimal('other_charges_total', 15, 2)->default(0);
            $table->decimal('other_charges_vat_rate', 5, 2)->default(0);
            $table->decimal('other_charges_vat_amount', 15, 2)->default(0);
            $table->decimal('vat_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->boolean('self_approved')->default(false);
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_reason')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_invoice_number_normalized']);
            $table->index(['status', 'expected_delivery_date']);
            $table->index(['supplier_id', 'purchase_date']);
            $table->index(['warehouse_id', 'status']);
            $table->index(['created_by_user_id', 'status']);
        });

        DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_values_check CHECK (currency = 'AED' AND status IN ('draft','approved','partially_received','fully_received','closed','cancelled') AND subtotal >= 0 AND discount_total >= 0 AND net_before_vat >= 0 AND shipping_total >= 0 AND shipping_vat_rate BETWEEN 0 AND 100 AND shipping_vat_amount >= 0 AND other_charges_total >= 0 AND other_charges_vat_rate BETWEEN 0 AND 100 AND other_charges_vat_amount >= 0 AND vat_total >= 0 AND grand_total >= 0)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refusePopulatedRollback('purchases');
        Schema::dropIfExists('purchases');
    }

    private function refusePopulatedRollback(string $table): void
    {
        if (Schema::hasTable($table) && DB::table($table)->exists()) {
            throw new RuntimeException("Rollback refused: {$table} contains business records.");
        }
    }
};
