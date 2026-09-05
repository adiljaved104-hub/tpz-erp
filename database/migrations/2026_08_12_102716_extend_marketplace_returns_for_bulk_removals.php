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
            $this->rebuildSqliteTables();

            return;
        }

        Schema::table('customer_returns', function (Blueprint $table): void {
            $table->foreignId('receiving_warehouse_id')->nullable()->change();
        });
        Schema::table('marketplace_return_removal_items', function (Blueprint $table): void {
            // Keep a dedicated supporting index for the removal FK before the
            // legacy composite unique index is replaced. MySQL may otherwise
            // select that unique index for the FK and refuse to drop it.
            $table->index('marketplace_return_removal_id', 'mrr_items_removal_fk_idx');
            $table->dropUnique('marketplace_removal_item_unique');
            $table->foreignId('customer_return_item_id')->nullable()->change();
            $table->string('source_stock_type')->default('marketplace_non_sellable')->after('product_id');
            $table->decimal('carried_value', 15, 4)->default(0)->after('unit_cost');
            $table->unique(['marketplace_return_removal_id', 'product_id', 'source_stock_type', 'customer_return_item_id'], 'marketplace_removal_source_unique');
        });
        DB::table('marketplace_return_removal_items')->update(['carried_value' => DB::raw('quantity * unit_cost')]);
        DB::statement("ALTER TABLE marketplace_return_removal_items ADD CONSTRAINT marketplace_removal_source_type_check CHECK(source_stock_type IN ('marketplace_non_sellable','marketplace_sellable') AND carried_value >= 0)");
        Schema::table('customer_return_inspections', function (Blueprint $table): void {
            $table->foreignId('customer_return_item_id')->nullable()->change();
            $table->foreignId('marketplace_return_removal_item_id')->nullable()->after('customer_return_item_id');
            $table->foreign('marketplace_return_removal_item_id', 'return_inspections_removal_item_fk')->references('id')->on('marketplace_return_removal_items')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE customer_return_inspections ADD CONSTRAINT customer_return_inspection_subject_check CHECK((customer_return_item_id IS NOT NULL AND marketplace_return_removal_item_id IS NULL) OR (customer_return_item_id IS NULL AND marketplace_return_removal_item_id IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_returns')->whereNull('receiving_warehouse_id')->exists()
            || DB::table('marketplace_return_removal_items')->whereNull('customer_return_item_id')->orWhere('source_stock_type', 'marketplace_sellable')->exists()
            || DB::table('customer_return_inspections')->whereNotNull('marketplace_return_removal_item_id')->exists()) {
            throw new RuntimeException('Rollback refused: bulk Marketplace Return data uses the extended schema.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTables(true);

            return;
        }

        DB::statement('ALTER TABLE customer_return_inspections DROP CHECK customer_return_inspection_subject_check');
        DB::statement('ALTER TABLE marketplace_return_removal_items DROP CHECK marketplace_removal_source_type_check');

        Schema::table('customer_return_inspections', function (Blueprint $table): void {
            $table->dropForeign('return_inspections_removal_item_fk');
            $table->dropColumn('marketplace_return_removal_item_id');
            $table->foreignId('customer_return_item_id')->nullable(false)->change();
        });
        Schema::table('marketplace_return_removal_items', function (Blueprint $table): void {
            $table->dropUnique('marketplace_removal_source_unique');
            $table->dropColumn(['source_stock_type', 'carried_value']);
            $table->foreignId('customer_return_item_id')->nullable(false)->change();
            $table->unique(['marketplace_return_removal_id', 'customer_return_item_id'], 'marketplace_removal_item_unique');
        });
        Schema::table('customer_returns', function (Blueprint $table): void {
            $table->foreignId('receiving_warehouse_id')->nullable(false)->change();
        });
    }

    private function rebuildSqliteTables(bool $rollback = false): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            $this->rebuildCustomerReturns($rollback);
            $this->rebuildRemovalItems($rollback);
            $this->rebuildInspections($rollback);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        if (DB::select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('Foreign-key violations detected after Marketplace Return schema rebuild.');
        }
    }

    private function rebuildCustomerReturns(bool $rollback): void
    {
        $nullability = $rollback ? 'NOT NULL' : 'NULL';
        DB::statement("CREATE TABLE customer_returns_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT, reference VARCHAR NOT NULL UNIQUE, order_id INTEGER NOT NULL,
            marketplace_platform_id INTEGER NULL, fulfillment_warehouse_id INTEGER NOT NULL,
            status VARCHAR NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','qc_pending','completed','cancelled')),
            return_source VARCHAR NOT NULL DEFAULT 'manual' CHECK(return_source IN ('manual','marketplace','api')),
            receiving_warehouse_id INTEGER {$nullability}, reported_at DATETIME NOT NULL, received_at DATETIME NULL,
            completed_at DATETIME NULL, cancelled_at DATETIME NULL, created_by_user_id INTEGER NOT NULL,
            received_by_user_id INTEGER NULL, cancellation_reason TEXT NULL, notes TEXT NULL,
            idempotency_key VARCHAR NOT NULL UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
            FOREIGN KEY(marketplace_platform_id) REFERENCES marketplace_platforms(id) ON DELETE RESTRICT,
            FOREIGN KEY(fulfillment_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
            FOREIGN KEY(receiving_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY(received_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('INSERT INTO customer_returns_new SELECT * FROM customer_returns');
        DB::statement('DROP TABLE customer_returns');
        DB::statement('ALTER TABLE customer_returns_new RENAME TO customer_returns');
        DB::statement('CREATE INDEX customer_returns_status_index ON customer_returns(status)');
        DB::statement('CREATE INDEX customer_returns_return_source_index ON customer_returns(return_source)');
        DB::statement('CREATE INDEX customer_returns_order_status_index ON customer_returns(order_id,status)');
        DB::statement('CREATE INDEX customer_returns_receiving_status_index ON customer_returns(receiving_warehouse_id,status)');
    }

    private function rebuildRemovalItems(bool $rollback): void
    {
        $customerReturnNullable = $rollback ? 'NOT NULL' : 'NULL';
        $extendedColumns = $rollback ? '' : ", source_stock_type VARCHAR NOT NULL DEFAULT 'marketplace_non_sellable' CHECK(source_stock_type IN ('marketplace_non_sellable','marketplace_sellable'))";
        $carriedColumn = $rollback ? '' : ', carried_value NUMERIC NOT NULL DEFAULT 0 CHECK(carried_value >= 0)';
        $unique = $rollback
            ? 'UNIQUE(marketplace_return_removal_id,customer_return_item_id)'
            : 'UNIQUE(marketplace_return_removal_id,product_id,source_stock_type,customer_return_item_id)';
        DB::statement("CREATE TABLE marketplace_return_removal_items_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT, marketplace_return_removal_id INTEGER NOT NULL,
            customer_return_item_id INTEGER {$customerReturnNullable}, product_id INTEGER NOT NULL{$extendedColumns},
            quantity INTEGER NOT NULL CHECK(quantity > 0), dispatched_quantity INTEGER NOT NULL DEFAULT 0 CHECK(dispatched_quantity >= 0 AND dispatched_quantity <= quantity),
            received_quantity INTEGER NOT NULL DEFAULT 0 CHECK(received_quantity >= 0 AND received_quantity <= dispatched_quantity),
            unit_cost NUMERIC NOT NULL CHECK(unit_cost >= 0){$carriedColumn}, source_product_inventory_id INTEGER NOT NULL,
            destination_product_inventory_id INTEGER NULL, posting_key VARCHAR NOT NULL UNIQUE,
            created_at DATETIME NULL, updated_at DATETIME NULL, {$unique},
            FOREIGN KEY(marketplace_return_removal_id) REFERENCES marketplace_return_removals(id) ON DELETE RESTRICT,
            FOREIGN KEY(customer_return_item_id) REFERENCES customer_return_items(id) ON DELETE RESTRICT,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
            FOREIGN KEY(source_product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
            FOREIGN KEY(destination_product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT)");
        $select = $rollback
            ? 'id,marketplace_return_removal_id,customer_return_item_id,product_id,quantity,dispatched_quantity,received_quantity,unit_cost,source_product_inventory_id,destination_product_inventory_id,posting_key,created_at,updated_at'
            : "id,marketplace_return_removal_id,customer_return_item_id,product_id,'marketplace_non_sellable',quantity,dispatched_quantity,received_quantity,unit_cost,quantity * unit_cost,source_product_inventory_id,destination_product_inventory_id,posting_key,created_at,updated_at";
        DB::statement("INSERT INTO marketplace_return_removal_items_new SELECT {$select} FROM marketplace_return_removal_items");
        DB::statement('DROP TABLE marketplace_return_removal_items');
        DB::statement('ALTER TABLE marketplace_return_removal_items_new RENAME TO marketplace_return_removal_items');
        DB::statement('CREATE INDEX marketplace_return_removal_items_return_item_index ON marketplace_return_removal_items(customer_return_item_id)');
        DB::statement('CREATE INDEX marketplace_return_removal_items_product_index ON marketplace_return_removal_items(product_id)');
    }

    private function rebuildInspections(bool $rollback): void
    {
        $nullable = $rollback ? 'NOT NULL' : 'NULL';
        $removalColumn = $rollback ? '' : ', marketplace_return_removal_item_id INTEGER NULL';
        $subjectCheck = $rollback ? '' : ', CHECK((customer_return_item_id IS NOT NULL AND marketplace_return_removal_item_id IS NULL) OR (customer_return_item_id IS NULL AND marketplace_return_removal_item_id IS NOT NULL))';
        $removalForeign = $rollback ? '' : ', FOREIGN KEY(marketplace_return_removal_item_id) REFERENCES marketplace_return_removal_items(id) ON DELETE RESTRICT';
        DB::statement("CREATE TABLE customer_return_inspections_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_return_item_id INTEGER {$nullable}{$removalColumn},
            quantity INTEGER NOT NULL CHECK(quantity > 0), result VARCHAR NOT NULL CHECK(result IN ('sellable','damaged')),
            inspected_by_user_id INTEGER NOT NULL, inspected_at DATETIME NOT NULL, notes TEXT NULL,
            posting_key VARCHAR NOT NULL UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL{$subjectCheck},
            FOREIGN KEY(customer_return_item_id) REFERENCES customer_return_items(id) ON DELETE RESTRICT{$removalForeign},
            FOREIGN KEY(inspected_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        $select = $rollback
            ? 'id,customer_return_item_id,quantity,result,inspected_by_user_id,inspected_at,notes,posting_key,created_at,updated_at'
            : 'id,customer_return_item_id,NULL,quantity,result,inspected_by_user_id,inspected_at,notes,posting_key,created_at,updated_at';
        DB::statement("INSERT INTO customer_return_inspections_new SELECT {$select} FROM customer_return_inspections");
        DB::statement('DROP TABLE customer_return_inspections');
        DB::statement('ALTER TABLE customer_return_inspections_new RENAME TO customer_return_inspections');
        DB::statement('CREATE INDEX customer_return_inspections_item_result_index ON customer_return_inspections(customer_return_item_id,result)');
        if (! $rollback) {
            DB::statement('CREATE INDEX customer_return_inspections_removal_item_index ON customer_return_inspections(marketplace_return_removal_item_id)');
        }
    }
};
