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
            $this->rebuildSqlite(includeQuickFields: true);

            return;
        }

        Schema::table('purchases', function (Blueprint $table): void {
            $table->string('entry_type')->default('standard')->index();
            $table->foreignId('handled_by_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_entry_type_check CHECK (entry_type IN ('standard','quick_stock'))");
    }

    public function down(): void
    {
        $this->refuseUnsafeRollback();

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqlite(includeQuickFields: false);

            return;
        }

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropForeign(['handled_by_employee_id']);
            $table->dropColumn('handled_by_employee_id');
            $table->dropIndex(['entry_type']);
            $table->dropColumn('entry_type');
        });
    }

    private function refuseUnsafeRollback(): void
    {
        if (Schema::hasColumn('purchases', 'entry_type')
            && DB::table('purchases')->where('entry_type', '<>', 'standard')->exists()) {
            throw new RuntimeException('Rollback refused: Quick Stock Purchase audit evidence would be lost.');
        }

        if (Schema::hasColumn('purchases', 'handled_by_employee_id')
            && DB::table('purchases')->whereNotNull('handled_by_employee_id')->exists()) {
            throw new RuntimeException('Rollback refused: handled Employee audit evidence would be lost.');
        }
    }

    private function rebuildSqlite(bool $includeQuickFields): void
    {
        $temporary = 'purchases_quick_stock_rebuild';
        throw_if(Schema::hasTable($temporary), RuntimeException::class, "Unexpected temporary table [{$temporary}] exists.");

        Schema::disableForeignKeyConstraints();

        try {
            DB::statement($this->sqliteCreateSql($temporary, $includeQuickFields));
            $columns = $this->purchaseColumns();
            $target = $includeQuickFields ? [...$columns, 'entry_type', 'handled_by_employee_id'] : $columns;
            $source = $includeQuickFields ? [...$columns, "'standard'", 'NULL'] : $columns;

            DB::statement(sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM purchases',
                $temporary,
                implode(', ', $target),
                implode(', ', $source),
            ));
            Schema::drop('purchases');
            Schema::rename($temporary, 'purchases');
            $this->createSqliteIndexes($includeQuickFields);
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
            'expected_delivery_date', 'external_accounting_reference', 'currency', 'status',
            'subtotal', 'discount_total', 'net_before_vat', 'shipping_total', 'shipping_vat_rate',
            'shipping_vat_amount', 'other_charges_total', 'other_charges_vat_rate',
            'other_charges_vat_amount', 'vat_total', 'grand_total', 'notes', 'created_by_user_id',
            'approved_by_user_id', 'approved_at', 'approval_reason', 'self_approved',
            'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason', 'closed_by_user_id',
            'closed_at', 'closure_reason', 'created_at', 'updated_at',
        ];
    }

    private function sqliteCreateSql(string $table, bool $includeQuickFields): string
    {
        $quickColumns = $includeQuickFields
            ? "\n    entry_type VARCHAR NOT NULL DEFAULT 'standard' CHECK (entry_type IN ('standard','quick_stock')),\n    handled_by_employee_id INTEGER NULL,"
            : '';
        $handledForeignKey = $includeQuickFields
            ? "\n    FOREIGN KEY (handled_by_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,"
            : '';

        return <<<SQL
CREATE TABLE {$table} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference VARCHAR NOT NULL UNIQUE,
    supplier_id INTEGER NULL,
    warehouse_id INTEGER NOT NULL,
    supplier_invoice_number VARCHAR NULL,
    supplier_invoice_number_normalized VARCHAR NULL,
    supplier_invoice_date DATE NULL,
    purchase_date DATE NOT NULL,
    expected_delivery_date DATE NULL,
    external_accounting_reference VARCHAR(255) NULL,{$quickColumns}
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
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,{$handledForeignKey}
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL;
    }

    private function createSqliteIndexes(bool $includeQuickFields): void
    {
        DB::statement('CREATE INDEX purchases_status_expected_index ON purchases (status, expected_delivery_date)');
        DB::statement('CREATE INDEX purchases_supplier_date_index ON purchases (supplier_id, purchase_date)');
        DB::statement('CREATE INDEX purchases_warehouse_status_index ON purchases (warehouse_id, status)');
        DB::statement('CREATE INDEX purchases_creator_status_index ON purchases (created_by_user_id, status)');
        DB::statement('CREATE INDEX purchases_external_accounting_reference_index ON purchases (external_accounting_reference)');

        if ($includeQuickFields) {
            DB::statement('CREATE INDEX purchases_entry_type_index ON purchases (entry_type)');
            DB::statement('CREATE INDEX purchases_handled_by_employee_id_index ON purchases (handled_by_employee_id)');
        }
    }
};
