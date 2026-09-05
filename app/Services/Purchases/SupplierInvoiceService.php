<?php

namespace App\Services\Purchases;

use App\Models\Purchase;

class SupplierInvoiceService
{
    public function normalize(?string $invoice): ?string
    {
        if ($invoice === null || trim($invoice) === '') {
            return null;
        }

        $normalized = mb_strtoupper(trim($invoice));
        $normalized = preg_replace('/[ _\/\\\\-]+/u', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-');

        return $normalized === '' ? null : $normalized;
    }

    public function conflictingPurchase(?int $supplierId, string $normalized, ?int $exceptPurchaseId = null): ?Purchase
    {
        if ($supplierId === null) {
            return null;
        }

        return Purchase::query()
            ->where('supplier_id', $supplierId)
            ->where('supplier_invoice_number_normalized', $normalized)
            ->when($exceptPurchaseId, fn ($query) => $query->whereKeyNot($exceptPurchaseId))
            ->first();
    }
}
