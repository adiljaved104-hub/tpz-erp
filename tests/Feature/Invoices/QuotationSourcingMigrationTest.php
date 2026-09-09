<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuotationSourcingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nullable_additions_preserve_existing_constraints_and_rollback_without_data_changes(): void
    {
        $this->assertTrue(Schema::hasColumn('quotations', 'warehouse_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'quotation_item_id'));
        foreach (['quotation_item_sourcing_instructions', 'quotation_sourcing_postings'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $columns = collect(DB::select('PRAGMA table_info(quotations)'))->keyBy('name');
        $this->assertSame(0, $columns['warehouse_id']->notnull);
        $columns = collect(DB::select('PRAGMA table_info(order_items)'))->keyBy('name');
        $this->assertSame(0, $columns['quotation_item_id']->notnull);
        $indexes = collect(DB::select('PRAGMA index_list(order_items)'))->keyBy('name');
        $this->assertSame(1, $indexes['order_items_quotation_item_uq']->unique);
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $before = DB::table('reference_sequences')->orderBy('key')->get()->toJson();
        $migration = require database_path('migrations/2026_09_08_090000_add_quotation_inventory_sourcing.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('quotations', 'warehouse_id'));
        $this->assertFalse(Schema::hasColumn('order_items', 'quotation_item_id'));
        $this->assertFalse(Schema::hasTable('quotation_sourcing_postings'));
        $migration->up();
        $this->assertSame($before, DB::table('reference_sequences')->orderBy('key')->get()->toJson());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_sourcing_tables_have_restrictive_links_checks_and_unique_posting_identity(): void
    {
        foreach (['quotation_item_sourcing_instructions' => 2, 'quotation_sourcing_postings' => 5] as $table => $count) {
            $fks = DB::select('PRAGMA foreign_key_list('.$table.')');
            $this->assertCount($count, $fks);
            foreach ($fks as $fk) {
                $this->assertSame('RESTRICT', $fk->on_delete);
            }
            $sql = DB::selectOne('SELECT sql FROM sqlite_master WHERE name = ?', [$table])->sql;
            $this->assertStringContainsString('CHECK', $sql);
            $this->assertStringContainsString('purchase_unit_cost > 0', $sql);
        }
        $sql = DB::selectOne('SELECT sql FROM sqlite_master WHERE name = ?', ['quotation_sourcing_postings'])->sql;
        $this->assertStringContainsString('idempotency_key VARCHAR(36) NOT NULL UNIQUE', $sql);
        $this->assertStringNotContainsString('updated_at', $sql);
        $this->assertSame([], DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name LIKE 'quotation%'"));
    }
}
