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
            $this->upSqlite();

            return;
        }

        $this->upMysql();
    }

    private function upSqlite(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE product_hardware_profiles (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 product_id INTEGER NOT NULL UNIQUE,
 profile_version INTEGER NOT NULL DEFAULT 1 CHECK (profile_version >= 1),
 ram_upgradeable INTEGER NOT NULL DEFAULT 0 CHECK (ram_upgradeable IN (0,1)),
 max_supported_ram_mb INTEGER NULL CHECK (max_supported_ram_mb IS NULL OR max_supported_ram_mb > 0),
 storage_upgradeable INTEGER NOT NULL DEFAULT 0 CHECK (storage_upgradeable IN (0,1)),
 notes TEXT NULL,
 created_by_user_id INTEGER NOT NULL,
 updated_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX product_hardware_profiles_version_idx ON product_hardware_profiles (product_id, profile_version)');

        DB::statement(<<<'SQL'
CREATE TABLE product_hardware_slots (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 product_hardware_profile_id INTEGER NOT NULL,
 subsystem VARCHAR(16) NOT NULL CHECK (subsystem IN ('ram','storage')),
 slot_key VARCHAR(64) NOT NULL,
 interface_type VARCHAR(120) NULL,
 is_soldered INTEGER NOT NULL DEFAULT 0 CHECK (is_soldered IN (0,1)),
 is_occupied INTEGER NOT NULL DEFAULT 0 CHECK (is_occupied IN (0,1)),
 base_component_id INTEGER NULL,
 base_capacity_value NUMERIC(12,4) NULL CHECK (base_capacity_value IS NULL OR base_capacity_value > 0),
 base_capacity_unit VARCHAR(16) NULL CHECK (base_capacity_unit IS NULL OR base_capacity_unit IN ('mb','gb','tb')),
 position INTEGER NOT NULL DEFAULT 0 CHECK (position >= 0),
 notes VARCHAR(1000) NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(product_hardware_profile_id, slot_key),
 CHECK ((base_capacity_value IS NULL AND base_capacity_unit IS NULL) OR (base_capacity_value IS NOT NULL AND base_capacity_unit IS NOT NULL)),
 CHECK (is_soldered = 0 OR is_occupied = 1),
 CHECK (base_component_id IS NULL OR is_occupied = 1),
 FOREIGN KEY(product_hardware_profile_id) REFERENCES product_hardware_profiles(id) ON DELETE RESTRICT,
 FOREIGN KEY(base_component_id) REFERENCES components(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX product_hardware_slots_subsystem_position_idx ON product_hardware_slots (product_hardware_profile_id, subsystem, position)');
        DB::statement('CREATE INDEX product_hardware_slots_base_component_idx ON product_hardware_slots (base_component_id)');

        DB::statement(<<<'SQL'
CREATE TABLE sales_configurations (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 product_id INTEGER NOT NULL,
 hardware_profile_version INTEGER NOT NULL CHECK (hardware_profile_version >= 1),
 display_name VARCHAR(255) NOT NULL,
 target_ram_mb INTEGER NULL CHECK (target_ram_mb IS NULL OR target_ram_mb > 0),
 target_storage_total_gb NUMERIC(12,4) NULL CHECK (target_storage_total_gb IS NULL OR target_storage_total_gb > 0),
 target_storage_layout TEXT NULL CHECK (target_storage_layout IS NULL OR JSON_VALID(target_storage_layout)),
 suggested_selling_addon NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK (suggested_selling_addon >= 0),
 default_selling_price NUMERIC(15,2) NULL CHECK (default_selling_price IS NULL OR default_selling_price >= 0),
 active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
 created_by_user_id INTEGER NOT NULL,
 updated_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(product_id, hardware_profile_version, display_name),
 CHECK (target_ram_mb IS NOT NULL OR target_storage_total_gb IS NOT NULL OR target_storage_layout IS NOT NULL),
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX sales_configurations_product_active_idx ON sales_configurations (product_id, active)');
        DB::statement('CREATE INDEX sales_configurations_profile_version_idx ON sales_configurations (product_id, hardware_profile_version)');

        DB::statement(<<<'SQL'
CREATE TABLE upgrade_recipes (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sales_configuration_id INTEGER NOT NULL,
 hardware_profile_version INTEGER NOT NULL CHECK (hardware_profile_version >= 1),
 name VARCHAR(255) NOT NULL,
 preferred INTEGER NOT NULL DEFAULT 0 CHECK (preferred IN (0,1)),
 priority INTEGER NOT NULL DEFAULT 100 CHECK (priority >= 0),
 labour_unit_cost NUMERIC(15,4) NOT NULL DEFAULT 0 CHECK (labour_unit_cost >= 0),
 active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
 created_by_user_id INTEGER NOT NULL,
 updated_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(sales_configuration_id, name),
 FOREIGN KEY(sales_configuration_id) REFERENCES sales_configurations(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX upgrade_recipes_selection_idx ON upgrade_recipes (sales_configuration_id, active, preferred, priority)');

        DB::statement(<<<'SQL'
CREATE TABLE upgrade_recipe_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 upgrade_recipe_id INTEGER NOT NULL,
 sequence INTEGER NOT NULL CHECK (sequence >= 1),
 operation VARCHAR(32) NOT NULL CHECK (operation IN ('keep','install','remove_and_return','remove_as_damaged','remove_and_discard')),
 source_slot_key VARCHAR(64) NULL,
 target_slot_key VARCHAR(64) NULL,
 install_component_id INTEGER NULL,
 recovered_component_id INTEGER NULL,
 quantity_per_laptop NUMERIC(12,4) NOT NULL DEFAULT 1 CHECK (quantity_per_laptop > 0),
 recovery_valuation_method VARCHAR(32) NOT NULL DEFAULT 'not_applicable' CHECK (recovery_valuation_method IN ('not_applicable','central_approved','override')),
 recovery_value_override NUMERIC(15,4) NULL CHECK (recovery_value_override IS NULL OR recovery_value_override >= 0),
 override_reason VARCHAR(1000) NULL,
 recovery_approved_by_user_id INTEGER NULL,
 recovery_approved_at DATETIME NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(upgrade_recipe_id, sequence),
 CHECK ((recovery_valuation_method = 'override' AND recovery_value_override IS NOT NULL AND recovery_approved_by_user_id IS NOT NULL AND recovery_approved_at IS NOT NULL AND LENGTH(TRIM(override_reason)) BETWEEN 5 AND 1000) OR (recovery_valuation_method <> 'override' AND recovery_value_override IS NULL AND override_reason IS NULL AND recovery_approved_by_user_id IS NULL AND recovery_approved_at IS NULL)),
 FOREIGN KEY(upgrade_recipe_id) REFERENCES upgrade_recipes(id) ON DELETE RESTRICT,
 FOREIGN KEY(install_component_id) REFERENCES components(id) ON DELETE RESTRICT,
 FOREIGN KEY(recovered_component_id) REFERENCES components(id) ON DELETE RESTRICT,
 FOREIGN KEY(recovery_approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX upgrade_recipe_lines_operation_idx ON upgrade_recipe_lines (upgrade_recipe_id, operation, sequence)');
        DB::statement('CREATE INDEX upgrade_recipe_lines_install_component_idx ON upgrade_recipe_lines (install_component_id)');
        DB::statement('CREATE INDEX upgrade_recipe_lines_recovered_component_idx ON upgrade_recipe_lines (recovered_component_id)');
    }

    private function upMysql(): void
    {
        Schema::create('product_hardware_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique('product_hardware_profiles_product_uq');
            $table->unsignedInteger('profile_version')->default(1);
            $table->boolean('ram_upgradeable')->default(false);
            $table->unsignedInteger('max_supported_ram_mb')->nullable();
            $table->boolean('storage_upgradeable')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id');
            $table->foreignId('updated_by_user_id');
            $table->timestamps();
            $table->foreign('product_id', 'product_hardware_profiles_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'product_hardware_profiles_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'product_hardware_profiles_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['product_id', 'profile_version'], 'product_hardware_profiles_version_idx');
        });
        DB::statement('ALTER TABLE product_hardware_profiles ADD CONSTRAINT product_hardware_profiles_values_chk CHECK (profile_version >= 1 AND (max_supported_ram_mb IS NULL OR max_supported_ram_mb > 0))');

        Schema::create('product_hardware_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_hardware_profile_id');
            $table->string('subsystem', 16);
            $table->string('slot_key', 64);
            $table->string('interface_type', 120)->nullable();
            $table->boolean('is_soldered')->default(false);
            $table->boolean('is_occupied')->default(false);
            $table->foreignId('base_component_id')->nullable();
            $table->decimal('base_capacity_value', 12, 4)->nullable();
            $table->string('base_capacity_unit', 16)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->unique(['product_hardware_profile_id', 'slot_key'], 'product_hardware_slots_profile_key_uq');
            $table->foreign('product_hardware_profile_id', 'product_hardware_slots_profile_fk')->references('id')->on('product_hardware_profiles')->restrictOnDelete();
            $table->foreign('base_component_id', 'product_hardware_slots_component_fk')->references('id')->on('components')->restrictOnDelete();
            $table->index(['product_hardware_profile_id', 'subsystem', 'position'], 'product_hardware_slots_subsystem_position_idx');
            $table->index('base_component_id', 'product_hardware_slots_base_component_idx');
        });
        DB::statement("ALTER TABLE product_hardware_slots ADD CONSTRAINT product_hardware_slots_values_chk CHECK (subsystem IN ('ram','storage') AND (base_capacity_value IS NULL OR base_capacity_value > 0) AND (base_capacity_unit IS NULL OR base_capacity_unit IN ('mb','gb','tb')) AND ((base_capacity_value IS NULL AND base_capacity_unit IS NULL) OR (base_capacity_value IS NOT NULL AND base_capacity_unit IS NOT NULL)) AND (is_soldered = 0 OR is_occupied = 1) AND (base_component_id IS NULL OR is_occupied = 1))");

        Schema::create('sales_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->unsignedInteger('hardware_profile_version');
            $table->string('display_name');
            $table->unsignedInteger('target_ram_mb')->nullable();
            $table->decimal('target_storage_total_gb', 12, 4)->nullable();
            $table->json('target_storage_layout')->nullable();
            $table->decimal('suggested_selling_addon', 15, 2)->default(0);
            $table->decimal('default_selling_price', 15, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id');
            $table->foreignId('updated_by_user_id');
            $table->timestamps();
            $table->unique(['product_id', 'hardware_profile_version', 'display_name'], 'sales_configurations_product_version_name_uq');
            $table->foreign('product_id', 'sales_configurations_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sales_configurations_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'sales_configurations_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['product_id', 'active'], 'sales_configurations_product_active_idx');
            $table->index(['product_id', 'hardware_profile_version'], 'sales_configurations_profile_version_idx');
        });
        DB::statement('ALTER TABLE sales_configurations ADD CONSTRAINT sales_configurations_values_chk CHECK (hardware_profile_version >= 1 AND (target_ram_mb IS NULL OR target_ram_mb > 0) AND (target_storage_total_gb IS NULL OR target_storage_total_gb > 0) AND suggested_selling_addon >= 0 AND (default_selling_price IS NULL OR default_selling_price >= 0) AND (target_ram_mb IS NOT NULL OR target_storage_total_gb IS NOT NULL OR target_storage_layout IS NOT NULL))');

        Schema::create('upgrade_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_configuration_id');
            $table->unsignedInteger('hardware_profile_version');
            $table->string('name');
            $table->boolean('preferred')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->decimal('labour_unit_cost', 15, 4)->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id');
            $table->foreignId('updated_by_user_id');
            $table->timestamps();
            $table->unique(['sales_configuration_id', 'name'], 'upgrade_recipes_configuration_name_uq');
            $table->foreign('sales_configuration_id', 'upgrade_recipes_configuration_fk')->references('id')->on('sales_configurations')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'upgrade_recipes_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'upgrade_recipes_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['sales_configuration_id', 'active', 'preferred', 'priority'], 'upgrade_recipes_selection_idx');
        });
        DB::statement('ALTER TABLE upgrade_recipes ADD CONSTRAINT upgrade_recipes_values_chk CHECK (hardware_profile_version >= 1 AND priority >= 0 AND labour_unit_cost >= 0)');

        Schema::create('upgrade_recipe_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('upgrade_recipe_id');
            $table->unsignedInteger('sequence');
            $table->string('operation', 32);
            $table->string('source_slot_key', 64)->nullable();
            $table->string('target_slot_key', 64)->nullable();
            $table->foreignId('install_component_id')->nullable();
            $table->foreignId('recovered_component_id')->nullable();
            $table->decimal('quantity_per_laptop', 12, 4)->default(1);
            $table->string('recovery_valuation_method', 32)->default('not_applicable');
            $table->decimal('recovery_value_override', 15, 4)->nullable();
            $table->string('override_reason', 1000)->nullable();
            $table->foreignId('recovery_approved_by_user_id')->nullable();
            $table->timestamp('recovery_approved_at')->nullable();
            $table->timestamps();
            $table->unique(['upgrade_recipe_id', 'sequence'], 'upgrade_recipe_lines_recipe_sequence_uq');
            $table->foreign('upgrade_recipe_id', 'upgrade_recipe_lines_recipe_fk')->references('id')->on('upgrade_recipes')->restrictOnDelete();
            $table->foreign('install_component_id', 'upgrade_recipe_lines_install_component_fk')->references('id')->on('components')->restrictOnDelete();
            $table->foreign('recovered_component_id', 'upgrade_recipe_lines_recovered_component_fk')->references('id')->on('components')->restrictOnDelete();
            $table->foreign('recovery_approved_by_user_id', 'upgrade_recipe_lines_recovery_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['upgrade_recipe_id', 'operation', 'sequence'], 'upgrade_recipe_lines_operation_idx');
            $table->index('install_component_id', 'upgrade_recipe_lines_install_component_idx');
            $table->index('recovered_component_id', 'upgrade_recipe_lines_recovered_component_idx');
        });
        DB::statement("ALTER TABLE upgrade_recipe_lines ADD CONSTRAINT upgrade_recipe_lines_values_chk CHECK (sequence >= 1 AND operation IN ('keep','install','remove_and_return','remove_as_damaged','remove_and_discard') AND quantity_per_laptop > 0 AND recovery_valuation_method IN ('not_applicable','central_approved','override') AND (recovery_value_override IS NULL OR recovery_value_override >= 0) AND ((recovery_valuation_method = 'override' AND recovery_value_override IS NOT NULL AND recovery_approved_by_user_id IS NOT NULL AND recovery_approved_at IS NOT NULL AND CHAR_LENGTH(TRIM(override_reason)) BETWEEN 5 AND 1000) OR (recovery_valuation_method <> 'override' AND recovery_value_override IS NULL AND override_reason IS NULL AND recovery_approved_by_user_id IS NULL AND recovery_approved_at IS NULL)))");
    }

    public function down(): void
    {
        foreach (['upgrade_recipe_lines', 'upgrade_recipes', 'sales_configurations', 'product_hardware_slots', 'product_hardware_profiles'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains business records.");
            }
        }

        Schema::dropIfExists('upgrade_recipe_lines');
        Schema::dropIfExists('upgrade_recipes');
        Schema::dropIfExists('sales_configurations');
        Schema::dropIfExists('product_hardware_slots');
        Schema::dropIfExists('product_hardware_profiles');
    }
};
