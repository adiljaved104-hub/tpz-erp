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
            $this->rebuildSqlite(nullableSupplier: true, includeExternalReference: true);

            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->change();
            $table->string('external_accounting_reference')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->refuseUnsafeRollback();

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqlite(nullableSupplier: false, includeExternalReference: false);

            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['external_accounting_reference']);
            $table->dropColumn('external_accounting_reference');
            $table->foreignId('supplier_id')->nullable(false)->change();
        });
    }

    private function refuseUnsafeRollback(): void
    {
        if (DB::table('purchases')->whereNull('supplier_id')->exists()) {
            throw new RuntimeException('Rollback refused: supplierless Purchases cannot be converted to a required Supplier.');
        }

        if (Schema::hasColumn('purchases', 'external_accounting_reference')
            && DB::table('purchases')->whereNotNull('external_accounting_reference')->exists()) {
            throw new RuntimeException('Rollback refused: external accounting references would be lost.');
        }
    }

    private function rebuildSqlite(bool $nullableSupplier, bool $includeExternalReference): void
    {
        $temporary = 'purchases_supplier_optional_rebuild';
        throw_if(Schema::hasTable($temporary), RuntimeException::class, "Unexpected temporary table [{$temporary}] exists.");

        Schema::disableForeignKeyConstraints();

        try {
            DB::statement($this->sqliteCreateSql($temporary, $nullableSupplier, $includeExternalReference));
            $columns = $this->purchaseColumns();
            $targetColumns = $includeExternalReference ? [...$columns, 'external_accounting_reference'] : $columns;
            $sourceColumns = $includeExternalReference ? [...$columns, 'NULL'] : $columns;

            DB::statement(sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM purchases',
                $temporary,
                implode(', ', $targetColumns),
                implode(', ', $sourceColumns),
            ));
            Schema::drop('purchases');
            Schema::rename($temporary, 'purchases');
            $this->createSqliteIndexes($includeExternalReference);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** @return array<int, string> */
    private function purchaseColumns(): array
    {
        return [
            'id', 'reference', 'supplier_id', 'warehouse_id', 'supplier_invoice_number',
            'supplier_invoice_number_normalized', 'supplier_invoice_date', 'purchase_date',
            'expected_delivery_date', 'currency', 'status', 'subtotal', 'discount_total',
            'net_before_vat', 'shipping_total', 'shipping_vat_rate', 'shipping_vat_amount',
            'other_charges_total', 'other_charges_vat_rate', 'other_charges_vat_amount',
            'vat_total', 'grand_total', 'notes', 'created_by_user_id', 'approved_by_user_id',
            'approved_at', 'approval_reason', 'self_approved', 'cancelled_by_user_id',
            'cancelled_at', 'cancellation_reason', 'closed_by_user_id', 'closed_at',
            'closure_reason', 'created_at', 'updated_at',
        ];
    }

    private function sqliteCreateSql(string $table, bool $nullableSupplier, bool $includeExternalReference): string
    {
        $supplierNullability = $nullableSupplier ? 'NULL' : 'NOT NULL';
        $externalReference = $includeExternalReference ? "\n    external_accounting_reference VARCHAR(255) NULL," : '';

        return <<<SQL
CREATE TABLE {$table} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    supplier_id INTEGER {$supplierNullability},
    warehouse_id INTEGER NOT NULL,
    supplier_invoice_number VARCHAR NULL,
    supplier_invoice_number_normalized VARCHAR NULL,
    supplier_invoice_date DATE NULL,
    purchase_date DATE NOT NULL,
    expected_delivery_date DATE NULL,{$externalReference}
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
SQL;
    }

    private function createSqliteIndexes(bool $includeExternalReference): void
    {
        DB::statement('CREATE INDEX purchases_status_expected_index ON purchases (status, expected_delivery_date)');
        DB::statement('CREATE INDEX purchases_supplier_date_index ON purchases (supplier_id, purchase_date)');
        DB::statement('CREATE INDEX purchases_warehouse_status_index ON purchases (warehouse_id, status)');
        DB::statement('CREATE INDEX purchases_creator_status_index ON purchases (created_by_user_id, status)');

        if ($includeExternalReference) {
            DB::statement('CREATE INDEX purchases_external_accounting_reference_index ON purchases (external_accounting_reference)');
        }
    }
};
