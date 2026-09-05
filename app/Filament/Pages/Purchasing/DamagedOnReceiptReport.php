<?php

namespace App\Filament\Pages\Purchasing;

use Illuminate\Support\Facades\DB;

class DamagedOnReceiptReport extends BasePurchaseReport
{
    protected static ?string $navigationLabel = 'Damaged / Rejected Receipts';

    public function rows(): array
    {
        return DB::table('purchase_receipt_items as pri')->join('purchase_receipts as pr', 'pr.id', '=', 'pri.purchase_receipt_id')->join('products as p', 'p.id', '=', 'pri.product_id')->where(fn ($q) => $q->where('pri.damaged_quantity', '>', 0)->orWhere('pri.rejected_quantity', '>', 0))->select(['pr.reference as grn', 'p.sku', 'p.name', 'pri.damaged_quantity', 'pri.rejected_quantity', 'pr.received_at'])->get()->map(fn ($r) => (array) $r)->all();
    }
}
