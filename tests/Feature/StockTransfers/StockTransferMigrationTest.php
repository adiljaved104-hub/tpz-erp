<?php

namespace Tests\Feature\StockTransfers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockTransferMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_constraints_and_reference_sequence_are_ready_without_business_rows(): void
    {
        foreach (['stock_transfers', 'stock_transfer_items', 'stock_transfer_status_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame(1, (int) DB::table('reference_sequences')->where('key', 'stock_transfer:'.now()->format('Y'))->value('next_value'));
        if (DB::getDriverName() === 'sqlite') {
            $transferSql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'stock_transfers')->value('sql');
            $itemSql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'stock_transfer_items')->value('sql');
            $this->assertStringContainsString('source_warehouse_id <> destination_warehouse_id', $transferSql);
            $this->assertStringContainsString('received_quantity + returned_quantity + lost_quantity <= dispatched_quantity', $itemSql);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        } else {
            $checks = DB::table('information_schema.check_constraints')
                ->where('constraint_schema', DB::getDatabaseName())
                ->whereIn('CONSTRAINT_NAME', ['stock_transfers_values_check', 'stock_transfer_items_values_check'])
                ->selectRaw('CONSTRAINT_NAME AS constraint_name, CHECK_CLAUSE AS check_clause')
                ->get()
                ->mapWithKeys(fn (object $row): array => [$row->constraint_name => str_replace('`', '', (string) $row->check_clause)]);
            $this->assertStringContainsString('source_warehouse_id <> destination_warehouse_id', (string) $checks['stock_transfers_values_check']);
            $this->assertStringContainsString('received_quantity + returned_quantity + lost_quantity <= dispatched_quantity', (string) $checks['stock_transfer_items_values_check']);
            $this->assertCount(2, $checks);
        }
    }
}
