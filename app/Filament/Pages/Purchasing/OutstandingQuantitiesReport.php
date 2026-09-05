<?php

namespace App\Filament\Pages\Purchasing;

use Illuminate\Support\Facades\DB;

class OutstandingQuantitiesReport extends BasePurchaseReport
{
    protected static ?string $navigationLabel = 'Outstanding by Product';

    public function rows(): array
    {
        return DB::table('purchase_items as pi')->join('purchases as p', 'p.id', '=', 'pi.purchase_id')->join('products as product', 'product.id', '=', 'pi.product_id')->whereIn('p.status', ['approved', 'partially_received'])->select('product.sku', 'product.name')->selectRaw('SUM(pi.ordered_quantity - pi.received_quantity) AS outstanding_quantity')->groupBy('product.id', 'product.sku', 'product.name')->get()->map(fn ($r) => (array) $r)->all();
    }
}
