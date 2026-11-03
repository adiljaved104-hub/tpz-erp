<?php

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManualSourcingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_sourcing_schema_is_additive_and_foreign_keys_are_restrictive(): void
    {
        $this->assertTrue(Schema::hasColumns('web_sales_manual_sourcing_lines', [
            'order_id', 'order_item_id', 'product_id', 'source_mode', 'status', 'description',
            'selling_price_snapshot', 'quantity', 'purchase_id', 'purchase_receipt_id', 'idempotency_key',
        ]));
        $indexes = collect(DB::select("PRAGMA index_list('web_sales_manual_sourcing_lines')"))->keyBy('name');
        $this->assertArrayHasKey('wsm_idempotency_uq', $indexes);
        $this->assertArrayHasKey('wsm_order_status_idx', $indexes);
        $this->assertSame('ok', DB::scalar('PRAGMA integrity_check'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
