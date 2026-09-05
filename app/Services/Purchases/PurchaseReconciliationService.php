<?php

namespace App\Services\Purchases;

use App\Models\PurchaseItem;
use Illuminate\Support\Collection;

class PurchaseReconciliationService
{
    public function discrepancies(): Collection
    {
        return PurchaseItem::query()->get()->map(function (PurchaseItem $item): ?array {
            $received = (int) $item->receiptItems()
                ->selectRaw('COALESCE(SUM(accepted_quantity + damaged_quantity), 0) AS aggregate')
                ->value('aggregate');
            $rejected = (int) $item->receiptItems()->sum('rejected_quantity');

            return $received === $item->received_quantity && $rejected === $item->rejected_quantity
                ? null
                : ['purchase_item_id' => $item->id, 'stored_received' => $item->received_quantity, 'ledger_received' => $received, 'stored_rejected' => $item->rejected_quantity, 'ledger_rejected' => $rejected];
        })->filter()->values();
    }
}
