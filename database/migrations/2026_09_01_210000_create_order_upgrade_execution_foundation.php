<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->assertCleanStartingState();

        if (DB::getDriverName() === 'sqlite') {
            $this->upSqlite();

            return;
        }

        $this->upMysql();
    }

    private function upSqlite(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            $this->rebuildOrderItemsForSqlite();
            $this->createUpgradeTablesForSqlite();
            $this->rebuildReservationsForSqlite();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        if (DB::table(DB::raw('pragma_foreign_key_check'))->exists()) {
            throw new RuntimeException('Phase 1C migration created a foreign-key violation.');
        }
    }

    private function rebuildOrderItemsForSqlite(): void
    {
        $rows = DB::table('order_items')->orderBy('order_id')->orderBy('id')->get();

        DB::statement(<<<'SQL'
CREATE TABLE order_items_phase1c (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 line_number INTEGER NOT NULL CHECK (line_number > 0),
 line_key VARCHAR(36) NOT NULL UNIQUE,
 product_name VARCHAR NOT NULL,
 sku VARCHAR NOT NULL,
 brand_name VARCHAR NULL,
 ordered_quantity INTEGER NOT NULL CHECK (ordered_quantity > 0),
 selling_price NUMERIC NOT NULL CHECK (selling_price >= 0),
 discount_total NUMERIC NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
 vat_rate NUMERIC NOT NULL DEFAULT 0 CHECK (vat_rate >= 0 AND vat_rate <= 100),
 vat_amount NUMERIC NOT NULL DEFAULT 0 CHECK (vat_amount >= 0),
 line_total NUMERIC NOT NULL CHECK (line_total >= 0),
 notes TEXT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(order_id, line_number),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT
)
SQL);

        $lineNumber = 0;
        $lastOrderId = null;
        foreach ($rows as $row) {
            if ($lastOrderId !== $row->order_id) {
                $lastOrderId = $row->order_id;
                $lineNumber = 0;
            }
            $lineNumber++;
            DB::table('order_items_phase1c')->insert([
                'id' => $row->id,
                'order_id' => $row->order_id,
                'product_id' => $row->product_id,
                'line_number' => $lineNumber,
                'line_key' => (string) Str::uuid(),
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'brand_name' => $row->brand_name,
                'ordered_quantity' => $row->ordered_quantity,
                'selling_price' => $row->selling_price,
                'discount_total' => $row->discount_total,
                'vat_rate' => $row->vat_rate,
                'vat_amount' => $row->vat_amount,
                'line_total' => $row->line_total,
                'notes' => $row->notes,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('order_items');
        Schema::rename('order_items_phase1c', 'order_items');
        DB::statement('CREATE INDEX order_items_product_order_index ON order_items (product_id, order_id)');
        DB::statement('CREATE INDEX order_items_order_line_index ON order_items (order_id, line_number)');
    }

    private function createUpgradeTablesForSqlite(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE order_item_upgrade_selections (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_item_id INTEGER NOT NULL UNIQUE,
 sales_configuration_id INTEGER NOT NULL,
 upgrade_recipe_id INTEGER NOT NULL,
 hardware_profile_version INTEGER NOT NULL CHECK (hardware_profile_version > 0),
 configuration_snapshot TEXT NOT NULL CHECK (JSON_VALID(configuration_snapshot)),
 recipe_snapshot TEXT NOT NULL CHECK (JSON_VALID(recipe_snapshot)),
 suggested_selling_addon_snapshot NUMERIC NOT NULL DEFAULT 0 CHECK (suggested_selling_addon_snapshot >= 0),
 labour_cost_snapshot NUMERIC NOT NULL DEFAULT 0 CHECK (labour_cost_snapshot >= 0),
 recovery_snapshot TEXT NOT NULL CHECK (JSON_VALID(recovery_snapshot)),
 selected_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(sales_configuration_id) REFERENCES sales_configurations(id) ON DELETE RESTRICT,
 FOREIGN KEY(upgrade_recipe_id) REFERENCES upgrade_recipes(id) ON DELETE RESTRICT,
 FOREIGN KEY(selected_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX order_item_upgrade_selections_configuration_idx ON order_item_upgrade_selections (sales_configuration_id, upgrade_recipe_id)');

        DB::statement(<<<'SQL'
CREATE TABLE order_upgrade_executions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_item_upgrade_selection_id INTEGER NOT NULL UNIQUE,
 order_fulfillment_item_id INTEGER NOT NULL UNIQUE,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 base_cogs NUMERIC NOT NULL CHECK (base_cogs >= 0),
 installed_component_cost NUMERIC NOT NULL CHECK (installed_component_cost >= 0),
 recovery_credit NUMERIC NOT NULL CHECK (recovery_credit >= 0 AND recovery_credit <= base_cogs),
 labour_cost NUMERIC NOT NULL CHECK (labour_cost >= 0),
 final_configured_cogs NUMERIC NOT NULL CHECK (final_configured_cogs >= 0),
 executed_by_user_id INTEGER NOT NULL,
 executed_at DATETIME NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(order_item_upgrade_selection_id) REFERENCES order_item_upgrade_selections(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_fulfillment_item_id) REFERENCES order_fulfillment_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(executed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX order_upgrade_executions_executed_at_idx ON order_upgrade_executions (executed_at)');

        DB::statement(<<<'SQL'
CREATE TABLE order_upgrade_execution_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_upgrade_execution_id INTEGER NOT NULL,
 upgrade_recipe_line_id INTEGER NULL,
 operation VARCHAR(32) NOT NULL CHECK (operation IN ('keep','install','remove_and_return','remove_as_damaged','remove_and_discard')),
 component_id INTEGER NULL,
 product_inventory_id INTEGER NULL,
 slot_snapshot TEXT NULL CHECK (slot_snapshot IS NULL OR JSON_VALID(slot_snapshot)),
 component_specification_snapshot TEXT NULL CHECK (component_specification_snapshot IS NULL OR JSON_VALID(component_specification_snapshot)),
 quantity INTEGER NOT NULL CHECK (quantity > 0),
 actual_unit_cost NUMERIC NOT NULL DEFAULT 0 CHECK (actual_unit_cost >= 0),
 total_value NUMERIC NOT NULL DEFAULT 0 CHECK (total_value >= 0),
 recovery_source_snapshot VARCHAR(32) NULL,
 recovery_approved_unit_value_snapshot NUMERIC NULL CHECK (recovery_approved_unit_value_snapshot IS NULL OR recovery_approved_unit_value_snapshot >= 0),
 recovery_applied_unit_value_snapshot NUMERIC NULL CHECK (recovery_applied_unit_value_snapshot IS NULL OR recovery_applied_unit_value_snapshot >= 0),
 stock_movement_id INTEGER NULL UNIQUE,
 created_at DATETIME NULL,
 FOREIGN KEY(order_upgrade_execution_id) REFERENCES order_upgrade_executions(id) ON DELETE RESTRICT,
 FOREIGN KEY(upgrade_recipe_line_id) REFERENCES upgrade_recipe_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY(component_id) REFERENCES components(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
 FOREIGN KEY(stock_movement_id) REFERENCES stock_movements(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX order_upgrade_execution_lines_execution_operation_idx ON order_upgrade_execution_lines (order_upgrade_execution_id, operation, id)');
        DB::statement('CREATE INDEX order_upgrade_execution_lines_component_idx ON order_upgrade_execution_lines (component_id)');
    }

    private function rebuildReservationsForSqlite(): void
    {
        $rows = DB::table('inventory_reservations')->orderBy('id')->get();

        DB::statement(<<<'SQL'
CREATE TABLE inventory_reservations_phase1c (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_item_id INTEGER NULL,
 order_item_upgrade_selection_id INTEGER NULL,
 upgrade_recipe_line_id INTEGER NULL,
 reservation_kind VARCHAR(32) NOT NULL DEFAULT 'base_product' CHECK (reservation_kind IN ('base_product','upgrade_component')),
 reservation_key VARCHAR(160) NOT NULL UNIQUE,
 reference VARCHAR NOT NULL UNIQUE,
 product_inventory_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 warehouse_id INTEGER NOT NULL,
 quantity INTEGER NOT NULL CHECK (quantity > 0),
 status VARCHAR NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'released', 'fulfilled')),
 reason TEXT NOT NULL,
 idempotency_key VARCHAR NOT NULL UNIQUE,
 release_idempotency_key VARCHAR NULL UNIQUE,
 reserved_by_user_id INTEGER NOT NULL,
 released_by_user_id INTEGER NULL,
 fulfilled_by_user_id INTEGER NULL,
 reserved_at DATETIME NOT NULL,
 released_at DATETIME NULL,
 fulfilled_at DATETIME NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK ((reservation_kind = 'base_product' AND order_item_id IS NOT NULL AND order_item_upgrade_selection_id IS NULL AND upgrade_recipe_line_id IS NULL) OR (reservation_kind = 'upgrade_component' AND order_item_id IS NOT NULL AND order_item_upgrade_selection_id IS NOT NULL AND upgrade_recipe_line_id IS NOT NULL) OR (reservation_kind = 'base_product' AND order_item_id IS NULL AND order_item_upgrade_selection_id IS NULL AND upgrade_recipe_line_id IS NULL)),
 FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_item_upgrade_selection_id) REFERENCES order_item_upgrade_selections(id) ON DELETE RESTRICT,
 FOREIGN KEY(upgrade_recipe_line_id) REFERENCES upgrade_recipe_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY(reserved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(released_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(fulfilled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);

        foreach ($rows as $row) {
            DB::table('inventory_reservations_phase1c')->insert([
                'id' => $row->id,
                'order_item_id' => $row->order_item_id,
                'order_item_upgrade_selection_id' => null,
                'upgrade_recipe_line_id' => null,
                'reservation_kind' => 'base_product',
                'reservation_key' => $row->order_item_id === null ? "legacy-reservation:{$row->id}" : "order-item:{$row->order_item_id}:base",
                'reference' => $row->reference,
                'product_inventory_id' => $row->product_inventory_id,
                'product_id' => $row->product_id,
                'warehouse_id' => $row->warehouse_id,
                'quantity' => $row->quantity,
                'status' => $row->status,
                'reason' => $row->reason,
                'idempotency_key' => $row->idempotency_key,
                'release_idempotency_key' => $row->release_idempotency_key,
                'reserved_by_user_id' => $row->reserved_by_user_id,
                'released_by_user_id' => $row->released_by_user_id,
                'fulfilled_by_user_id' => $row->fulfilled_by_user_id,
                'reserved_at' => $row->reserved_at,
                'released_at' => $row->released_at,
                'fulfilled_at' => $row->fulfilled_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('inventory_reservations');
        Schema::rename('inventory_reservations_phase1c', 'inventory_reservations');
        DB::statement('CREATE INDEX inventory_reservations_status_index ON inventory_reservations (status)');
        DB::statement('CREATE INDEX inventory_reservations_product_warehouse_status_index ON inventory_reservations (product_id, warehouse_id, status)');
        DB::statement('CREATE INDEX inventory_reservations_order_item_kind_index ON inventory_reservations (order_item_id, reservation_kind, status)');
        DB::statement('CREATE INDEX inventory_reservations_upgrade_selection_index ON inventory_reservations (order_item_upgrade_selection_id, status)');
    }

    private function upMysql(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('line_number')->nullable()->after('product_id');
            $table->uuid('line_key')->nullable()->after('line_number');
            // MySQL may use the legacy unique index to support the order_id FK.
            // Keep a temporary replacement until the new line-identity index exists.
            $table->index('order_id', 'order_items_phase1c_order_fk_tmp');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropUnique('order_items_order_id_product_id_unique');
        });
        $this->backfillOrderLineIdentity();
        DB::statement('ALTER TABLE order_items MODIFY line_number INT UNSIGNED NOT NULL, MODIFY line_key CHAR(36) NOT NULL');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unique(['order_id', 'line_number'], 'order_items_order_line_uq');
            $table->unique('line_key', 'order_items_line_key_uq');
            $table->index(['order_id', 'line_number'], 'order_items_order_line_index');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_phase1c_order_fk_tmp');
        });

        $this->createUpgradeTablesForMysql();

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            // MySQL may use the legacy unique index to support the order_item_id FK.
            $table->index('order_item_id', 'inventory_reservations_phase1c_item_fk_tmp');
        });
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropUnique('inventory_reservations_order_item_id_unique');
            $table->foreignId('order_item_upgrade_selection_id')->nullable()->after('order_item_id');
            $table->foreignId('upgrade_recipe_line_id')->nullable()->after('order_item_upgrade_selection_id');
            $table->string('reservation_kind', 32)->default('base_product')->after('upgrade_recipe_line_id');
            $table->string('reservation_key', 160)->nullable()->after('reservation_kind');
            $table->foreign('order_item_upgrade_selection_id', 'inventory_reservations_upgrade_selection_fk')->references('id')->on('order_item_upgrade_selections')->restrictOnDelete();
            $table->foreign('upgrade_recipe_line_id', 'inventory_reservations_recipe_line_fk')->references('id')->on('upgrade_recipe_lines')->restrictOnDelete();
        });
        DB::table('inventory_reservations')->orderBy('id')->eachById(function (object $row): void {
            DB::table('inventory_reservations')->where('id', $row->id)->update([
                'reservation_key' => $row->order_item_id === null ? "legacy-reservation:{$row->id}" : "order-item:{$row->order_item_id}:base",
            ]);
        });
        DB::statement('ALTER TABLE inventory_reservations MODIFY reservation_key VARCHAR(160) NOT NULL');
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->unique('reservation_key', 'inventory_reservations_reservation_key_uq');
            $table->index(['order_item_id', 'reservation_kind', 'status'], 'inventory_reservations_order_item_kind_index');
            $table->index(['order_item_upgrade_selection_id', 'status'], 'inventory_reservations_upgrade_selection_index');
        });
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_phase1c_item_fk_tmp');
        });
        DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_upgrade_values_chk CHECK (reservation_kind IN ('base_product','upgrade_component') AND ((reservation_kind = 'base_product' AND order_item_upgrade_selection_id IS NULL AND upgrade_recipe_line_id IS NULL) OR (reservation_kind = 'upgrade_component' AND order_item_id IS NOT NULL AND order_item_upgrade_selection_id IS NOT NULL AND upgrade_recipe_line_id IS NOT NULL)))");
    }

    private function backfillOrderLineIdentity(): void
    {
        $lastOrderId = null;
        $lineNumber = 0;
        foreach (DB::table('order_items')->orderBy('order_id')->orderBy('id')->cursor() as $row) {
            if ($lastOrderId !== $row->order_id) {
                $lastOrderId = $row->order_id;
                $lineNumber = 0;
            }
            $lineNumber++;
            DB::table('order_items')->where('id', $row->id)->update([
                'line_number' => $lineNumber,
                'line_key' => (string) Str::uuid(),
            ]);
        }
    }

    private function createUpgradeTablesForMysql(): void
    {
        Schema::create('order_item_upgrade_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->unique('order_item_upgrade_selections_item_uq');
            $table->foreignId('sales_configuration_id');
            $table->foreignId('upgrade_recipe_id');
            $table->unsignedInteger('hardware_profile_version');
            $table->json('configuration_snapshot');
            $table->json('recipe_snapshot');
            $table->decimal('suggested_selling_addon_snapshot', 15, 2)->default(0);
            $table->decimal('labour_cost_snapshot', 15, 4)->default(0);
            $table->json('recovery_snapshot');
            $table->foreignId('selected_by_user_id');
            $table->timestamps();
            $table->foreign('order_item_id', 'order_item_upgrade_selections_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('sales_configuration_id', 'order_item_upgrade_selections_configuration_fk')->references('id')->on('sales_configurations')->restrictOnDelete();
            $table->foreign('upgrade_recipe_id', 'order_item_upgrade_selections_recipe_fk')->references('id')->on('upgrade_recipes')->restrictOnDelete();
            $table->foreign('selected_by_user_id', 'order_item_upgrade_selections_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['sales_configuration_id', 'upgrade_recipe_id'], 'order_item_upgrade_selections_configuration_idx');
        });
        DB::statement('ALTER TABLE order_item_upgrade_selections ADD CONSTRAINT order_item_upgrade_selections_values_chk CHECK (hardware_profile_version > 0 AND suggested_selling_addon_snapshot >= 0 AND labour_cost_snapshot >= 0)');

        Schema::create('order_upgrade_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_upgrade_selection_id')->unique('order_upgrade_executions_selection_uq');
            $table->foreignId('order_fulfillment_item_id')->unique('order_upgrade_executions_fulfillment_item_uq');
            $table->uuid('idempotency_key')->unique('order_upgrade_executions_idempotency_uq');
            $table->decimal('base_cogs', 15, 4);
            $table->decimal('installed_component_cost', 15, 4);
            $table->decimal('recovery_credit', 15, 4);
            $table->decimal('labour_cost', 15, 4);
            $table->decimal('final_configured_cogs', 15, 4);
            $table->foreignId('executed_by_user_id');
            $table->timestamp('executed_at');
            $table->timestamps();
            $table->foreign('order_item_upgrade_selection_id', 'order_upgrade_executions_selection_fk')->references('id')->on('order_item_upgrade_selections')->restrictOnDelete();
            $table->foreign('order_fulfillment_item_id', 'order_upgrade_executions_fulfillment_item_fk')->references('id')->on('order_fulfillment_items')->restrictOnDelete();
            $table->foreign('executed_by_user_id', 'order_upgrade_executions_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index('executed_at', 'order_upgrade_executions_executed_at_idx');
        });
        DB::statement('ALTER TABLE order_upgrade_executions ADD CONSTRAINT order_upgrade_executions_values_chk CHECK (base_cogs >= 0 AND installed_component_cost >= 0 AND recovery_credit >= 0 AND recovery_credit <= base_cogs AND labour_cost >= 0 AND final_configured_cogs >= 0)');

        Schema::create('order_upgrade_execution_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_upgrade_execution_id');
            $table->foreignId('upgrade_recipe_line_id')->nullable();
            $table->string('operation', 32);
            $table->foreignId('component_id')->nullable();
            $table->foreignId('product_inventory_id')->nullable();
            $table->json('slot_snapshot')->nullable();
            $table->json('component_specification_snapshot')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('actual_unit_cost', 15, 4)->default(0);
            $table->decimal('total_value', 15, 4)->default(0);
            $table->string('recovery_source_snapshot', 32)->nullable();
            $table->decimal('recovery_approved_unit_value_snapshot', 15, 4)->nullable();
            $table->decimal('recovery_applied_unit_value_snapshot', 15, 4)->nullable();
            $table->foreignId('stock_movement_id')->nullable()->unique('order_upgrade_execution_lines_movement_uq');
            $table->timestamp('created_at')->nullable();
            $table->foreign('order_upgrade_execution_id', 'order_upgrade_execution_lines_execution_fk')->references('id')->on('order_upgrade_executions')->restrictOnDelete();
            $table->foreign('upgrade_recipe_line_id', 'order_upgrade_execution_lines_recipe_line_fk')->references('id')->on('upgrade_recipe_lines')->restrictOnDelete();
            $table->foreign('component_id', 'order_upgrade_execution_lines_component_fk')->references('id')->on('components')->restrictOnDelete();
            $table->foreign('product_inventory_id', 'order_upgrade_execution_lines_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'order_upgrade_execution_lines_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->index(['order_upgrade_execution_id', 'operation', 'id'], 'order_upgrade_execution_lines_execution_operation_idx');
            $table->index('component_id', 'order_upgrade_execution_lines_component_idx');
        });
        DB::statement("ALTER TABLE order_upgrade_execution_lines ADD CONSTRAINT order_upgrade_execution_lines_values_chk CHECK (operation IN ('keep','install','remove_and_return','remove_as_damaged','remove_and_discard') AND quantity > 0 AND actual_unit_cost >= 0 AND total_value >= 0 AND (recovery_approved_unit_value_snapshot IS NULL OR recovery_approved_unit_value_snapshot >= 0) AND (recovery_applied_unit_value_snapshot IS NULL OR recovery_applied_unit_value_snapshot >= 0))");
    }

    public function down(): void
    {
        foreach (['order_upgrade_execution_lines', 'order_upgrade_executions', 'order_item_upgrade_selections'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains immutable Phase 1C business records.");
            }
        }
        if (DB::table('inventory_reservations')->where('reservation_kind', 'upgrade_component')->orWhereNotNull('order_item_upgrade_selection_id')->exists()) {
            throw new RuntimeException('Rollback refused: component reservations exist.');
        }
        if (DB::table('order_items')->select('order_id', 'product_id')->groupBy('order_id', 'product_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Rollback refused: mixed Product lines require Phase 1C line identity.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->downSqlite();

            return;
        }

        $this->downMysql();
    }

    private function downSqlite(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            $this->rebuildReservationsWithoutPhase1cForSqlite();
            Schema::dropIfExists('order_upgrade_execution_lines');
            Schema::dropIfExists('order_upgrade_executions');
            Schema::dropIfExists('order_item_upgrade_selections');
            $this->rebuildOrderItemsWithoutPhase1cForSqlite();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function rebuildReservationsWithoutPhase1cForSqlite(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE inventory_reservations_pre_phase1c (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_item_id INTEGER NULL UNIQUE,
 reference VARCHAR NOT NULL UNIQUE,
 product_inventory_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 warehouse_id INTEGER NOT NULL,
 quantity INTEGER NOT NULL CHECK (quantity > 0),
 status VARCHAR NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'released', 'fulfilled')),
 reason TEXT NOT NULL,
 idempotency_key VARCHAR NOT NULL UNIQUE,
 release_idempotency_key VARCHAR NULL UNIQUE,
 reserved_by_user_id INTEGER NOT NULL,
 released_by_user_id INTEGER NULL,
 fulfilled_by_user_id INTEGER NULL,
 reserved_at DATETIME NOT NULL,
 released_at DATETIME NULL,
 fulfilled_at DATETIME NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_inventory_id) REFERENCES product_inventories(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY(reserved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(released_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(fulfilled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('INSERT INTO inventory_reservations_pre_phase1c (id,order_item_id,reference,product_inventory_id,product_id,warehouse_id,quantity,status,reason,idempotency_key,release_idempotency_key,reserved_by_user_id,released_by_user_id,fulfilled_by_user_id,reserved_at,released_at,fulfilled_at,created_at,updated_at) SELECT id,order_item_id,reference,product_inventory_id,product_id,warehouse_id,quantity,status,reason,idempotency_key,release_idempotency_key,reserved_by_user_id,released_by_user_id,fulfilled_by_user_id,reserved_at,released_at,fulfilled_at,created_at,updated_at FROM inventory_reservations');
        Schema::drop('inventory_reservations');
        Schema::rename('inventory_reservations_pre_phase1c', 'inventory_reservations');
        DB::statement('CREATE INDEX inventory_reservations_status_index ON inventory_reservations (status)');
        DB::statement('CREATE INDEX inventory_reservations_product_warehouse_status_index ON inventory_reservations (product_id, warehouse_id, status)');
    }

    private function rebuildOrderItemsWithoutPhase1cForSqlite(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE order_items_pre_phase1c (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 product_name VARCHAR NOT NULL,
 sku VARCHAR NOT NULL,
 brand_name VARCHAR NULL,
 ordered_quantity INTEGER NOT NULL CHECK (ordered_quantity > 0),
 selling_price NUMERIC NOT NULL CHECK (selling_price >= 0),
 discount_total NUMERIC NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
 vat_rate NUMERIC NOT NULL DEFAULT 0 CHECK (vat_rate >= 0 AND vat_rate <= 100),
 vat_amount NUMERIC NOT NULL DEFAULT 0 CHECK (vat_amount >= 0),
 line_total NUMERIC NOT NULL CHECK (line_total >= 0),
 notes TEXT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(order_id, product_id),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('INSERT INTO order_items_pre_phase1c (id,order_id,product_id,product_name,sku,brand_name,ordered_quantity,selling_price,discount_total,vat_rate,vat_amount,line_total,notes,created_at,updated_at) SELECT id,order_id,product_id,product_name,sku,brand_name,ordered_quantity,selling_price,discount_total,vat_rate,vat_amount,line_total,notes,created_at,updated_at FROM order_items');
        Schema::drop('order_items');
        Schema::rename('order_items_pre_phase1c', 'order_items');
        DB::statement('CREATE INDEX order_items_product_order_index ON order_items (product_id, order_id)');
    }

    private function downMysql(): void
    {
        DB::statement('ALTER TABLE inventory_reservations DROP CHECK inventory_reservations_upgrade_values_chk');
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            // Restore the legacy FK-supporting unique index before removing the
            // Phase 1C composite index used by MySQL for order_item_id.
            $table->unique('order_item_id', 'inventory_reservations_order_item_id_unique');
        });
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropForeign('inventory_reservations_upgrade_selection_fk');
            $table->dropForeign('inventory_reservations_recipe_line_fk');
            $table->dropUnique('inventory_reservations_reservation_key_uq');
            $table->dropIndex('inventory_reservations_order_item_kind_index');
            $table->dropIndex('inventory_reservations_upgrade_selection_index');
            $table->dropColumn(['order_item_upgrade_selection_id', 'upgrade_recipe_line_id', 'reservation_kind', 'reservation_key']);
        });
        Schema::dropIfExists('order_upgrade_execution_lines');
        Schema::dropIfExists('order_upgrade_executions');
        Schema::dropIfExists('order_item_upgrade_selections');
        Schema::table('order_items', function (Blueprint $table): void {
            // The legacy uniqueness also provides the order_id FK index.
            $table->unique(['order_id', 'product_id'], 'order_items_order_id_product_id_unique');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropUnique('order_items_order_line_uq');
            $table->dropUnique('order_items_line_key_uq');
            $table->dropIndex('order_items_order_line_index');
            $table->dropColumn(['line_number', 'line_key']);
        });
    }

    private function assertCleanStartingState(): void
    {
        foreach (['order_item_upgrade_selections', 'order_upgrade_executions', 'order_upgrade_execution_lines'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Phase 1C migration refused: {$table} already exists.");
            }
        }
        if (Schema::hasColumn('order_items', 'line_key') || Schema::hasColumn('inventory_reservations', 'reservation_kind')) {
            throw new RuntimeException('Phase 1C migration refused: a partial schema change was detected.');
        }
    }
};
