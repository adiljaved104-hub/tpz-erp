<?php

namespace App\Filament\Pages\Purchasing;

use App\Services\Purchases\PurchaseReportService;

class ExpectedDeliveriesReport extends BasePurchaseReport
{
    protected static ?string $navigationLabel = 'Expected Deliveries';

    public function rows(): array
    {
        return app(PurchaseReportService::class)->expected(auth()->user())->with('supplier:id,name')->get()->map(fn ($p) => ['expected_delivery' => $p->expected_delivery_date?->toDateString(), 'reference' => $p->reference, 'supplier' => $p->supplier?->name ?? 'No Supplier', 'status' => $p->status->getLabel()])->all();
    }
}
