<?php

namespace App\Filament\Pages\Purchasing;

use App\Services\Purchases\PurchaseReportService;

class OpenPurchasesReport extends BasePurchaseReport
{
    protected static ?string $navigationLabel = 'Open Purchases';

    public function rows(): array
    {
        return app(PurchaseReportService::class)->open(auth()->user())->with('supplier:id,name')->get()->map(fn ($p) => ['reference' => $p->reference, 'supplier' => $p->supplier?->name ?? 'No Supplier', 'status' => $p->status->getLabel(), 'expected_delivery' => $p->expected_delivery_date?->toDateString()])->all();
    }
}
