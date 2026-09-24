<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_allocation_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_key', 190)->unique('iaa_identity_uq');
            $table->string('type', 20)->index('iaa_type_idx');
            $table->foreignId('employee_id')->nullable();
            $table->unique('employee_id', 'iaa_employee_uq');
            $table->foreign('employee_id', 'iaa_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreignId('team_id')->nullable();
            $table->unique('team_id', 'iaa_team_uq');
            $table->foreign('team_id', 'iaa_team_fk')->references('id')->on('teams')->restrictOnDelete();
            $table->string('name', 190);
            $table->boolean('is_system')->default(false)->index('iaa_system_idx');
            $table->boolean('status')->default(true)->index('iaa_status_idx');
            $table->timestamps();
        });
        Schema::create('inventory_allocation_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 40)->unique('ias_singleton_uq');
            $table->string('enforcement_mode', 30)->default('migration_shadow');
            $table->string('default_policy', 30)->default('no_automatic');
            $table->foreignId('updated_by_user_id')->nullable();
            $table->foreign('updated_by_user_id', 'ias_updated_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('inventory_allocation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->foreignId('target_account_id');
            $table->foreign('target_account_id', 'ialr_target_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('product_id')->nullable();
            $table->foreign('product_id', 'ialr_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreignId('product_brand_id')->nullable();
            $table->foreign('product_brand_id', 'ialr_brand_fk')->references('id')->on('product_brands')->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable();
            $table->foreign('product_category_id', 'ialr_category_fk')->references('id')->on('product_categories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable();
            $table->foreign('warehouse_id', 'ialr_warehouse_fk')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(100)->index('ialr_priority_idx');
            $table->boolean('status')->default(true)->index('ialr_status_idx');
            $table->foreignId('created_by_user_id');
            $table->foreign('created_by_user_id', 'ialr_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('inventory_allocation_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreign('account_id', 'iab_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('product_inventory_id');
            $table->foreign('product_inventory_id', 'iab_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->unsignedInteger('allocated_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->timestamps();
            $table->unique(['account_id', 'product_inventory_id'], 'iab_account_inventory_uq');
        });
        Schema::create('inventory_allocation_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_key')->unique('iae_event_key_uq');
            $table->string('event_type', 40)->index('iae_event_type_idx');
            $table->foreignId('product_inventory_id');
            $table->foreign('product_inventory_id', 'iae_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreignId('from_account_id')->nullable();
            $table->foreign('from_account_id', 'iae_from_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable();
            $table->foreign('to_account_id', 'iae_to_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->nullableMorphs('source', 'iae_source_idx');
            $table->foreignId('order_id')->nullable();
            $table->foreign('order_id', 'iae_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')->nullable();
            $table->foreign('purchase_receipt_id', 'iae_receipt_fk')->references('id')->on('purchase_receipts')->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->nullable();
            $table->foreign('performed_by_user_id', 'iae_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['product_inventory_id', 'created_at'], 'iae_inventory_created_idx');
        });
        Schema::create('inventory_allocation_reservation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_reservation_id');
            $table->foreign('inventory_reservation_id', 'iarl_reservation_fk')->references('id')->on('inventory_reservations')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->foreign('account_id', 'iarl_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('reserved')->index('iarl_status_idx');
            $table->timestamps();
            $table->unique(['inventory_reservation_id', 'account_id'], 'iarl_reservation_account_uq');
        });
        Schema::create('purchase_receipt_allocation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_item_id');
            $table->foreign('purchase_receipt_item_id', 'pral_receipt_item_fk')->references('id')->on('purchase_receipt_items')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->foreign('account_id', 'pral_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('allocation_method', 30);
            $table->timestamps();
            $table->unique(['purchase_receipt_item_id', 'account_id'], 'pral_receipt_account_uq');
        });
        Schema::create('inventory_allocation_transition_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('context_type', 30)->index('iatl_context_type_idx');
            $table->unsignedBigInteger('context_id')->index('iatl_context_id_idx');
            $table->foreignId('account_id');
            $table->foreign('account_id', 'iatl_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('source_product_inventory_id');
            $table->foreign('source_product_inventory_id', 'iatl_source_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreignId('destination_product_inventory_id')->nullable();
            $table->foreign('destination_product_inventory_id', 'iatl_destination_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable();
            $table->foreign('order_item_id', 'iatl_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('remaining_quantity')->default(0);
            $table->string('status', 20)->index('iatl_status_idx');
            $table->timestamps();
            $table->unique(['context_type', 'context_id', 'account_id', 'source_product_inventory_id'], 'iatl_context_account_inventory_uq');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_allocation_accounts ADD CONSTRAINT iaa_identity_chk CHECK ((is_system = 1 AND type = 'system' AND employee_id IS NULL AND team_id IS NULL) OR (is_system = 0 AND type = 'employee' AND employee_id IS NOT NULL AND team_id IS NULL) OR (is_system = 0 AND type = 'team' AND employee_id IS NULL AND team_id IS NOT NULL))");
            DB::statement('ALTER TABLE inventory_allocation_balances ADD CONSTRAINT iab_values_chk CHECK (allocated_quantity >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= allocated_quantity)');
        } else {
            DB::statement("CREATE TRIGGER iaa_identity_bi BEFORE INSERT ON inventory_allocation_accounts WHEN NOT ((NEW.is_system = 1 AND NEW.type = 'system' AND NEW.employee_id IS NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'employee' AND NEW.employee_id IS NOT NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'team' AND NEW.employee_id IS NULL AND NEW.team_id IS NOT NULL)) BEGIN SELECT RAISE(ABORT, 'Invalid allocation account identity'); END");
            DB::statement("CREATE TRIGGER iaa_identity_bu BEFORE UPDATE ON inventory_allocation_accounts WHEN NOT ((NEW.is_system = 1 AND NEW.type = 'system' AND NEW.employee_id IS NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'employee' AND NEW.employee_id IS NOT NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'team' AND NEW.employee_id IS NULL AND NEW.team_id IS NOT NULL)) BEGIN SELECT RAISE(ABORT, 'Invalid allocation account identity'); END");
            DB::statement("CREATE TRIGGER iab_values_bi BEFORE INSERT ON inventory_allocation_balances WHEN NEW.allocated_quantity < 0 OR NEW.reserved_quantity < 0 OR NEW.reserved_quantity > NEW.allocated_quantity BEGIN SELECT RAISE(ABORT, 'Invalid allocation balance'); END");
            DB::statement("CREATE TRIGGER iab_values_bu BEFORE UPDATE ON inventory_allocation_balances WHEN NEW.allocated_quantity < 0 OR NEW.reserved_quantity < 0 OR NEW.reserved_quantity > NEW.allocated_quantity BEGIN SELECT RAISE(ABORT, 'Invalid allocation balance'); END");
        }

        $now = now();
        $systemId = DB::table('inventory_allocation_accounts')->insertGetId([
            'identity_key' => 'system', 'type' => 'system', 'name' => 'System / Unallocated', 'is_system' => true, 'status' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_allocation_settings')->insert([
            'id' => 1, 'singleton_key' => 'inventory_allocation', 'enforcement_mode' => 'migration_shadow', 'default_policy' => 'no_automatic',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('product_inventories')->where('available_quantity', '>', 0)->orderBy('id')->chunkById(500, function ($inventories) use ($systemId, $now): void {
            $inventories->each(function (object $inventory) use ($systemId, $now): void {
                DB::table('inventory_allocation_balances')->insert([
                    'account_id' => $systemId, 'product_inventory_id' => $inventory->id,
                    'allocated_quantity' => $inventory->available_quantity,
                    'reserved_quantity' => $inventory->reserved_quantity,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('inventory_allocation_events')->insert([
                    'event_key' => (string) Str::uuid(), 'event_type' => 'legacy_reconciliation',
                    'product_inventory_id' => $inventory->id, 'to_account_id' => $systemId,
                    'quantity' => $inventory->available_quantity, 'reason' => 'Phase E legacy inventory reconciliation',
                    'metadata' => json_encode(['migration' => '2026_09_23_090000']), 'created_at' => $now,
                ]);
            });
        });
        DB::table('inventory_reservations')->where('status', 'active')->orderBy('id')->chunkById(500, function ($reservations) use ($systemId, $now): void {
            $reservations->each(function (object $reservation) use ($systemId, $now): void {
                DB::table('inventory_allocation_reservation_lines')->insert([
                    'inventory_reservation_id' => $reservation->id, 'account_id' => $systemId,
                    'quantity' => $reservation->quantity, 'status' => 'reserved',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            });
        });
    }

    public function down(): void
    {
        $hasOperationalData = DB::table('inventory_allocation_rules')->exists()
            || DB::table('purchase_receipt_allocation_lines')->exists()
            || DB::table('inventory_allocation_transition_lines')->exists()
            || DB::table('inventory_allocation_accounts')->where('is_system', false)->exists()
            || DB::table('inventory_allocation_events')->where('event_type', '!=', 'legacy_reconciliation')->exists();
        if ($hasOperationalData) {
            throw new RuntimeException('Rollback refused: the Inventory Allocation ledger contains operational records.');
        }
        Schema::dropIfExists('purchase_receipt_allocation_lines');
        Schema::dropIfExists('inventory_allocation_transition_lines');
        Schema::dropIfExists('inventory_allocation_reservation_lines');
        Schema::dropIfExists('inventory_allocation_events');
        Schema::dropIfExists('inventory_allocation_balances');
        Schema::dropIfExists('inventory_allocation_rules');
        Schema::dropIfExists('inventory_allocation_settings');
        Schema::dropIfExists('inventory_allocation_accounts');
    }
};
