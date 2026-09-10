<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Actions\Purchases\UpdateDraftPurchase;
use App\DTOs\Purchases\UpdatePurchaseData;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Purchases\PurchaseFormLineService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $items = $this->getRecord()->items()->get()->map(fn ($item): array => [
            'product_id' => $item->product_id, 'ordered_quantity' => $item->ordered_quantity, 'unit_cost' => $item->unit_cost,
            'line_discount_total' => $item->line_discount_total, 'vat_rate' => $item->vat_rate, 'notes' => $item->notes,
        ])->all();
        $user = auth()->user();
        $data['items'] = $user instanceof User
            ? app(PurchaseFormLineService::class)->enrich($items, (int) $data['warehouse_id'], $user, $this->getRecord())
            : $items;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Purchase $record */
        return app(UpdateDraftPurchase::class)->handle($record, new UpdatePurchaseData(
            supplierId: filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null, warehouseId: (int) $data['warehouse_id'], purchaseDate: (string) $data['purchase_date'],
            items: CreatePurchase::items($data['items']), supplierInvoiceNumber: $data['supplier_invoice_number'] ?? null,
            supplierInvoiceDate: $data['supplier_invoice_date'] ?? null, expectedDeliveryDate: $data['expected_delivery_date'] ?? null,
            externalAccountingReference: $data['external_accounting_reference'] ?? null,
            shippingTotal: (string) ($data['shipping_total'] ?? '0.00'), shippingVatRate: (string) ($data['shipping_vat_rate'] ?? '0.00'),
            otherChargesTotal: (string) ($data['other_charges_total'] ?? '0.00'), otherChargesVatRate: (string) ($data['other_charges_vat_rate'] ?? '0.00'), notes: $data['notes'] ?? null,
            handledByEmployeeId: filled($data['handled_by_employee_id'] ?? null) ? (int) $data['handled_by_employee_id'] : null,
        ), auth()->user());
    }
}
