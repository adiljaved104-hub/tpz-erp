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
            DB::statement("ALTER TABLE products ADD COLUMN inventory_item_type VARCHAR(20) NOT NULL DEFAULT 'product' CHECK (inventory_item_type IN ('product','component'))");
            DB::statement('CREATE INDEX products_inventory_item_type_status_idx ON products (inventory_item_type, status)');
            DB::statement(<<<'SQL'
CREATE TABLE components (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 product_id INTEGER NOT NULL UNIQUE,
 component_type VARCHAR(32) NOT NULL CHECK (component_type IN ('ram','ssd','battery','wifi_card','gpu_module','other')),
 specification VARCHAR(255) NOT NULL,
 capacity_value NUMERIC(12,4) NULL CHECK (capacity_value IS NULL OR capacity_value > 0),
 capacity_unit VARCHAR(16) NULL CHECK (capacity_unit IS NULL OR capacity_unit IN ('mb','gb','tb','mah','other')),
 interface_type VARCHAR(120) NULL,
 attributes TEXT NULL CHECK (attributes IS NULL OR JSON_VALID(attributes)),
 approved_oem_recovery_value NUMERIC(15,4) NOT NULL DEFAULT 0 CHECK (approved_oem_recovery_value >= 0),
 recovery_approved_by_user_id INTEGER NULL,
 recovery_approved_at DATETIME NULL,
 recovery_reason VARCHAR(1000) NULL,
 created_by_user_id INTEGER NOT NULL,
 updated_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK ((approved_oem_recovery_value = 0 AND recovery_approved_by_user_id IS NULL AND recovery_approved_at IS NULL AND recovery_reason IS NULL) OR (approved_oem_recovery_value > 0 AND recovery_approved_by_user_id IS NOT NULL AND recovery_approved_at IS NOT NULL AND LENGTH(TRIM(recovery_reason)) BETWEEN 5 AND 1000)),
 CHECK ((capacity_value IS NULL AND capacity_unit IS NULL) OR (capacity_value IS NOT NULL AND capacity_unit IS NOT NULL)),
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(recovery_approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX components_type_specification_idx ON components (component_type, specification)');
            DB::statement('CREATE INDEX components_interface_type_idx ON components (interface_type)');
            DB::statement(<<<'SQL'
CREATE TABLE component_recovery_value_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 component_id INTEGER NOT NULL,
 old_value NUMERIC(15,4) NOT NULL CHECK (old_value >= 0),
 new_value NUMERIC(15,4) NOT NULL CHECK (new_value >= 0),
 reason VARCHAR(1000) NOT NULL CHECK (LENGTH(TRIM(reason)) BETWEEN 5 AND 1000),
 actor_user_id INTEGER NOT NULL,
 recorded_at DATETIME NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(component_id) REFERENCES components(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX component_recovery_events_component_recorded_idx ON component_recovery_value_events (component_id, recorded_at)');

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('inventory_item_type', 20)->default('product')->after('sku');
            $table->index(['inventory_item_type', 'status'], 'products_inventory_item_type_status_idx');
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_inventory_item_type_chk CHECK (inventory_item_type IN ('product','component'))");

        Schema::create('components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique('components_product_uq');
            $table->string('component_type', 32);
            $table->string('specification');
            $table->decimal('capacity_value', 12, 4)->nullable();
            $table->string('capacity_unit', 16)->nullable();
            $table->string('interface_type', 120)->nullable();
            $table->json('attributes')->nullable();
            $table->decimal('approved_oem_recovery_value', 15, 4)->default(0);
            $table->foreignId('recovery_approved_by_user_id')->nullable();
            $table->timestamp('recovery_approved_at')->nullable();
            $table->string('recovery_reason', 1000)->nullable();
            $table->foreignId('created_by_user_id');
            $table->foreignId('updated_by_user_id');
            $table->timestamps();

            $table->foreign('product_id', 'components_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('recovery_approved_by_user_id', 'components_recovery_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'components_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'components_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['component_type', 'specification'], 'components_type_specification_idx');
            $table->index('interface_type', 'components_interface_type_idx');
        });
        DB::statement("ALTER TABLE components ADD CONSTRAINT components_values_chk CHECK (component_type IN ('ram','ssd','battery','wifi_card','gpu_module','other') AND approved_oem_recovery_value >= 0 AND (capacity_value IS NULL OR capacity_value > 0) AND (capacity_unit IS NULL OR capacity_unit IN ('mb','gb','tb','mah','other')) AND ((capacity_value IS NULL AND capacity_unit IS NULL) OR (capacity_value IS NOT NULL AND capacity_unit IS NOT NULL)) AND ((approved_oem_recovery_value = 0 AND recovery_approved_by_user_id IS NULL AND recovery_approved_at IS NULL AND recovery_reason IS NULL) OR (approved_oem_recovery_value > 0 AND recovery_approved_by_user_id IS NOT NULL AND recovery_approved_at IS NOT NULL AND CHAR_LENGTH(TRIM(recovery_reason)) BETWEEN 5 AND 1000)))");

        Schema::create('component_recovery_value_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_id');
            $table->decimal('old_value', 15, 4);
            $table->decimal('new_value', 15, 4);
            $table->string('reason', 1000);
            $table->foreignId('actor_user_id');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('component_id', 'component_recovery_events_component_fk')->references('id')->on('components')->restrictOnDelete();
            $table->foreign('actor_user_id', 'component_recovery_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['component_id', 'recorded_at'], 'component_recovery_events_component_recorded_idx');
        });
        DB::statement('ALTER TABLE component_recovery_value_events ADD CONSTRAINT component_recovery_events_values_chk CHECK (old_value >= 0 AND new_value >= 0 AND CHAR_LENGTH(TRIM(reason)) BETWEEN 5 AND 1000)');
    }

    public function down(): void
    {
        if (Schema::hasTable('component_recovery_value_events') && DB::table('component_recovery_value_events')->exists()) {
            throw new RuntimeException('Rollback refused: Component recovery-value audit events exist.');
        }

        if (Schema::hasTable('components') && DB::table('components')->exists()) {
            throw new RuntimeException('Rollback refused: Component catalog records exist.');
        }

        if (Schema::hasColumn('products', 'inventory_item_type')
            && DB::table('products')->where('inventory_item_type', 'component')->exists()) {
            throw new RuntimeException('Rollback refused: Component Product records exist.');
        }

        Schema::dropIfExists('component_recovery_value_events');
        Schema::dropIfExists('components');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_inventory_item_type_status_idx');
            $table->dropColumn('inventory_item_type');
        });
    }
};
