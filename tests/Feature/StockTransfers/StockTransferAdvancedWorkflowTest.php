<?php

namespace Tests\Feature\StockTransfers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockTransferAdvancedWorkflowTest extends TestCase
{
    public function test_transfer_reservation_and_marketplace_loss_records_are_additive_and_idempotent(): void
    {
        $this->assertTrue(Schema::hasColumns('stock_transfer_reservations', [
            'stock_transfer_id', 'stock_transfer_item_id', 'inventory_reservation_id', 'quantity', 'status', 'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasColumns('stock_transfer_marketplace_losses', [
            'stock_transfer_id', 'stock_transfer_item_id', 'quantity', 'status', 'marketplace_reference', 'idempotency_key',
        ]));
        $indexes = collect(DB::select("PRAGMA index_list('stock_transfer_reservations')"))->pluck('name');
        $this->assertTrue($indexes->contains('str_active_item_uq'));
        $this->assertTrue($indexes->contains('str_transfer_status_idx'));
        $this->assertSame(0, DB::table('stock_transfer_reservations')->count());
        $this->assertSame(0, DB::table('stock_transfer_marketplace_losses')->count());
    }
}
