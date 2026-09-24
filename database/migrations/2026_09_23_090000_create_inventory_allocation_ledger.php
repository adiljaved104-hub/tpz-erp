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
            $table->string('identity_key', 190)->unique();
            $table->string('type', 20)->index();
            $table->foreignId('employee_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('name', 190);
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('status')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('inventory_allocation_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 40)->unique();
            $table->string('enforcement_mode', 30)->default('migration_shadow');
            $table->string('default_policy', 30)->default('no_automatic');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('inventory_allocation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->foreignId('target_account_id')->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('inventory_allocation_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('product_inventory_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('allocated_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->timestamps();
            $table->unique(['account_id', 'product_inventory_id'], 'allocation_balance_account_inventory_unique');
        });
        Schema::create('inventory_allocation_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->string('event_type', 40)->index();
            $table->foreignId('product_inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_account_id')->nullable()->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->nullableMorphs('source');
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['product_inventory_id', 'created_at'], 'allocation_events_inventory_created_index');
        });
        Schema::create('inventory_allocation_reservation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('reserved')->index();
            $table->timestamps();
            $table->unique(['inventory_reservation_id', 'account_id'], 'allocation_reservation_account_unique');
        });
        Schema::create('purchase_receipt_allocation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('allocation_method', 30);
            $table->timestamps();
            $table->unique(['purchase_receipt_item_id', 'account_id'], 'receipt_allocation_account_unique');
        });
        Schema::create('inventory_allocation_transition_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('context_type', 30)->index();
            $table->unsignedBigInteger('context_id')->index();
            $table->foreignId('account_id')->constrained('inventory_allocation_accounts')->restrictOnDelete();
            $table->foreignId('source_product_inventory_id')->constrained('product_inventories')->restrictOnDelete();
            $table->foreignId('destination_product_inventory_id')->nullable()->constrained('product_inventories')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('remaining_quantity')->default(0);
            $table->string('status', 20)->index();
            $table->timestamps();
            $table->unique(['context_type', 'context_id', 'account_id', 'source_product_inventory_id'], 'allocation_transition_context_account_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_allocation_accounts ADD CONSTRAINT allocation_accounts_identity_check CHECK ((is_system = 1 AND type = 'system' AND employee_id IS NULL AND team_id IS NULL) OR (is_system = 0 AND type = 'employee' AND employee_id IS NOT NULL AND team_id IS NULL) OR (is_system = 0 AND type = 'team' AND employee_id IS NULL AND team_id IS NOT NULL))");
            DB::statement('ALTER TABLE inventory_allocation_balances ADD CONSTRAINT allocation_balances_values_check CHECK (allocated_quantity >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= allocated_quantity)');
        } else {
            DB::statement("CREATE TRIGGER allocation_accounts_identity_insert BEFORE INSERT ON inventory_allocation_accounts WHEN NOT ((NEW.is_system = 1 AND NEW.type = 'system' AND NEW.employee_id IS NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'employee' AND NEW.employee_id IS NOT NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'team' AND NEW.employee_id IS NULL AND NEW.team_id IS NOT NULL)) BEGIN SELECT RAISE(ABORT, 'Invalid allocation account identity'); END");
            DB::statement("CREATE TRIGGER allocation_accounts_identity_update BEFORE UPDATE ON inventory_allocation_accounts WHEN NOT ((NEW.is_system = 1 AND NEW.type = 'system' AND NEW.employee_id IS NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'employee' AND NEW.employee_id IS NOT NULL AND NEW.team_id IS NULL) OR (NEW.is_system = 0 AND NEW.type = 'team' AND NEW.employee_id IS NULL AND NEW.team_id IS NOT NULL)) BEGIN SELECT RAISE(ABORT, 'Invalid allocation account identity'); END");
            DB::statement("CREATE TRIGGER allocation_balances_values_insert BEFORE INSERT ON inventory_allocation_balances WHEN NEW.allocated_quantity < 0 OR NEW.reserved_quantity < 0 OR NEW.reserved_quantity > NEW.allocated_quantity BEGIN SELECT RAISE(ABORT, 'Invalid allocation balance'); END");
            DB::statement("CREATE TRIGGER allocation_balances_values_update BEFORE UPDATE ON inventory_allocation_balances WHEN NEW.allocated_quantity < 0 OR NEW.reserved_quantity < 0 OR NEW.reserved_quantity > NEW.allocated_quantity BEGIN SELECT RAISE(ABORT, 'Invalid allocation balance'); END");
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
