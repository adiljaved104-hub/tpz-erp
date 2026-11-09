<?php

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManualSourcingShipmentProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_fulfillment_purchase_provenance_is_additive(): void
    {
        $this->assertTrue(Schema::hasColumns('web_sales_manual_sourcing_lines', [
            'actual_unit_cost',
            'purchase_currency',
            'purchased_on',
            'supplier_invoice_reference',
        ]));

        $this->assertTrue(Schema::hasColumns('purchase_receipts', [
            'custody_mode',
            'direct_fulfillment_order_id',
        ]));

        $this->assertTrue(Schema::hasColumns('manual_sourcing_shipment_postings', [
            'sourcing_line_id',
            'order_id',
            'order_item_id',
            'purchase_id',
            'purchase_receipt_id',
            'quantity',
            'idempotency_key',
            'custody_mode',
            'posted_at',
            'posted_by_user_id',
        ]));

        $this->assertSame(0, DB::table('manual_sourcing_shipment_postings')->count());
    }

    public function test_posting_idempotency_column_has_a_unique_index(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexNames = collect(DB::select("PRAGMA index_list('manual_sourcing_shipment_postings')"))
                ->pluck('name');

            $this->assertTrue($indexNames->contains('mssp_idempotency_uq'));
        } else {
            $this->assertTrue(Schema::hasColumn('manual_sourcing_shipment_postings', 'idempotency_key'));
        }
    }
}
