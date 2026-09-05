<?php

namespace Tests\Feature\Returns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceReturnMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_return_schema_has_required_constraints_and_unconsumed_sequence(): void
    {
        foreach ([
            'customer_return_marketplace_dispositions',
            'marketplace_return_removals',
            'marketplace_return_removal_items',
            'marketplace_return_removal_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertTrue(Schema::hasColumn('customer_return_items', 'company_receiving_warehouse_id'));
        $dispositionSql = $this->tableSql('customer_return_marketplace_dispositions');
        $removalSql = $this->tableSql('marketplace_return_removals');
        $itemSql = $this->tableSql('marketplace_return_removal_items');
        $returnSql = $this->tableSql('customer_returns');
        $inspectionSql = $this->tableSql('customer_return_inspections');
        $this->assertStringContainsString('quantity > 0', $dispositionSql);
        $this->assertStringContainsString("result IN ('sellable','non_sellable')", $dispositionSql);
        $this->assertStringContainsString('source_warehouse_id != destination_warehouse_id', $removalSql);
        $this->assertStringContainsString('dispatched_quantity <= quantity', $itemSql);
        $this->assertStringContainsString('received_quantity <= dispatched_quantity', $itemSql);
        $this->assertStringContainsString('receiving_warehouse_id INTEGER NULL', $returnSql);
        $this->assertStringContainsString('customer_return_item_id INTEGER NULL', $itemSql);
        $this->assertStringContainsString("source_stock_type IN ('marketplace_non_sellable','marketplace_sellable')", $itemSql);
        $this->assertStringContainsString('marketplace_return_removal_item_id INTEGER NULL', $inspectionSql);
        $this->assertStringContainsString('customer_return_item_id IS NULL AND marketplace_return_removal_item_id IS NOT NULL', $inspectionSql);
        $this->assertDatabaseHas('reference_sequences', [
            'key' => 'marketplace_return_removal:'.now()->year,
            'next_value' => 1,
        ]);
    }

    public function test_empty_marketplace_return_schema_rolls_back_cleanly_with_foreign_keys_enabled(): void
    {
        foreach ([
            '2026_08_12_102716_extend_marketplace_returns_for_bulk_removals.php',
            '2026_08_12_085538_initialize_marketplace_return_removal_reference_sequence.php',
            '2026_08_12_085517_create_marketplace_return_removal_events_table.php',
            '2026_08_12_085513_create_marketplace_return_removal_items_table.php',
            '2026_08_12_085509_create_marketplace_return_removals_table.php',
            '2026_08_12_085504_create_customer_return_marketplace_dispositions_table.php',
            '2026_08_12_085459_add_company_receiving_location_to_customer_return_items.php',
        ] as $migration) {
            (require database_path("migrations/{$migration}"))->down();
        }

        $this->assertFalse(Schema::hasTable('marketplace_return_removals'));
        $this->assertFalse(Schema::hasTable('customer_return_marketplace_dispositions'));
        $this->assertFalse(Schema::hasColumn('customer_return_items', 'company_receiving_warehouse_id'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    private function tableSql(string $table): string
    {
        return (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
    }
}
