<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Actions\Purchases\CreatePurchase as CreatePurchaseAction;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\Filament\Resources\Purchases\PurchaseResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreatePurchaseAction::class)->handle(new CreatePurchaseData(
            supplierId: filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null, warehouseId: (int) $data['warehouse_id'], purchaseDate: (string) $data['purchase_date'],
            items: self::items($data['items']), supplierInvoiceNumber: $data['supplier_invoice_number'] ?? null,
            supplierInvoiceDate: $data['supplier_invoice_date'] ?? null, expectedDeliveryDate: $data['expected_delivery_date'] ?? null,
            externalAccountingReference: $data['external_accounting_reference'] ?? null,
            shippingTotal: (string) ($data['shipping_total'] ?? '0.00'), shippingVatRate: (string) ($data['shipping_vat_rate'] ?? '0.00'),
            otherChargesTotal: (string) ($data['other_charges_total'] ?? '0.00'), otherChargesVatRate: (string) ($data['other_charges_vat_rate'] ?? '0.00'), notes: $data['notes'] ?? null,
        ), auth()->user());
    }

    /** @return array<int, PurchaseItemData> */
    public static function items(array $items): array
    {
        return array_map(fn (array $item): PurchaseItemData => new PurchaseItemData(
            (int) $item['product_id'], (int) $item['ordered_quantity'], (string) $item['unit_cost'],
            (string) ($item['line_discount_total'] ?? '0.00'), (string) ($item['vat_rate'] ?? '0.00'), $item['notes'] ?? null,
        ), array_values($items));
    }
}
