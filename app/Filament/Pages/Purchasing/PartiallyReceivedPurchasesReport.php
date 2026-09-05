<?php

namespace App\Filament\Pages\Purchasing;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;

class PartiallyReceivedPurchasesReport extends BasePurchaseReport
{
    protected static ?string $navigationLabel = 'Partially Received';

    public function rows(): array
    {
        return Purchase::query()->where('status', PurchaseStatus::PartiallyReceived)->with('supplier:id,name')->get()->map(fn ($p) => ['reference' => $p->reference, 'supplier' => $p->supplier?->name ?? 'No Supplier', 'status' => $p->status->getLabel()])->all();
    }
}
