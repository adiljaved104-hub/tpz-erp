<?php

namespace App\Filament\Pages\Purchasing;

use App\Models\Purchase;

class SupplierInvoiceRegister extends BasePurchaseReport
{
    protected static ?string $navigationLabel = 'Supplier Invoice Register';

    public function rows(): array
    {
        return Purchase::query()->whereNotNull('supplier_invoice_number')->with('supplier:id,name')->get()->map(fn ($p) => ['purchase' => $p->reference, 'supplier' => $p->supplier?->name ?? 'No Supplier', 'invoice' => $p->supplier_invoice_number, 'invoice_date' => $p->supplier_invoice_date?->toDateString(), 'status' => $p->status->getLabel()])->all();
    }
}
