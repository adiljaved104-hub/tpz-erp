<?php

namespace Tests\Feature\Upgrades;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderUpgradeExecutionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_1c_schema_has_line_identity_snapshots_executions_and_line_safe_reservations(): void
    {
        $this->assertTrue(Schema::hasColumns('order_items', ['line_number', 'line_key']));
        $this->assertTrue(Schema::hasColumns('inventory_reservations', [
            'order_item_upgrade_selection_id', 'upgrade_recipe_line_id', 'reservation_kind', 'reservation_key',
        ]));
        $this->assertTrue(Schema::hasColumns('order_item_upgrade_selections', [
            'order_item_id', 'sales_configuration_id', 'upgrade_recipe_id', 'hardware_profile_version',
            'configuration_snapshot', 'recipe_snapshot', 'suggested_selling_addon_snapshot',
            'labour_cost_snapshot', 'recovery_snapshot', 'selected_by_user_id',
        ]));
        $this->assertTrue(Schema::hasColumns('order_upgrade_executions', [
            'order_item_upgrade_selection_id', 'order_fulfillment_item_id', 'idempotency_key',
            'base_cogs', 'installed_component_cost', 'recovery_credit', 'labour_cost',
            'final_configured_cogs', 'executed_by_user_id', 'executed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('order_upgrade_execution_lines', [
            'order_upgrade_execution_id', 'upgrade_recipe_line_id', 'operation', 'component_id',
            'product_inventory_id', 'slot_snapshot', 'component_specification_snapshot', 'quantity',
            'actual_unit_cost', 'total_value', 'recovery_source_snapshot',
            'recovery_approved_unit_value_snapshot', 'recovery_applied_unit_value_snapshot', 'stock_movement_id',
        ]));

        $orderItemSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'order_items')->value('sql');
        $reservationSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'inventory_reservations')->value('sql');
        $executionSql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'order_upgrade_executions')->value('sql');

        $this->assertStringContainsString('UNIQUE(order_id, line_number)', $orderItemSql);
        $this->assertStringNotContainsString('UNIQUE(order_id, product_id)', $orderItemSql);
        $this->assertStringContainsString("reservation_kind IN ('base_product','upgrade_component')", $reservationSql);
        $this->assertStringContainsString('recovery_credit <= base_cogs', $executionSql);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_phase_1c_sqlite_rollback_restores_legacy_schema_when_no_phase_1c_business_rows_exist(): void
    {
        $exit = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_01_210000_create_order_upgrade_execution_foundation.php',
            '--force' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFalse(Schema::hasTable('order_item_upgrade_selections'));
        $this->assertFalse(Schema::hasTable('order_upgrade_executions'));
        $this->assertFalse(Schema::hasTable('order_upgrade_execution_lines'));
        $this->assertFalse(Schema::hasColumn('order_items', 'line_number'));
        $this->assertFalse(Schema::hasColumn('inventory_reservations', 'reservation_kind'));
        $this->assertStringContainsString(
            'UNIQUE(order_id, product_id)',
            (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'order_items')->value('sql'),
        );
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
