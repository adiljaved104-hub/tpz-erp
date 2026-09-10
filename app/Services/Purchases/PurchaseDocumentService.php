<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\UpdatePurchaseData;
use App\Enums\ProductStatus;
use App\Exceptions\DuplicateSupplierInvoiceException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchaseDocumentService
{
    public function __construct(
        private readonly PurchaseTotalsCalculator $totals,
        private readonly SupplierInvoiceService $invoices,
        private readonly OrderResponsibilityScopeService $responsibilities,
    ) {}

    /** @return array{header: array<string,mixed>, lines: array<int,array<string,mixed>>} */
    public function prepare(CreatePurchaseData|UpdatePurchaseData $data, ?Purchase $purchase = null, ?User $actor = null): array
    {
        Validator::make([
            'supplier_id' => $data->supplierId,
            'warehouse_id' => $data->warehouseId,
            'purchase_date' => $data->purchaseDate,
            'expected_delivery_date' => $data->expectedDeliveryDate,
            'supplier_invoice_date' => $data->supplierInvoiceDate,
            'external_accounting_reference' => $data->externalAccountingReference,
            'items' => $data->items,
        ], [
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'purchase_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'supplier_invoice_date' => ['nullable', 'date'],
            'external_accounting_reference' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
        ])->validate();

        $supplier = $data->supplierId === null ? null : Supplier::query()->findOrFail($data->supplierId);
        $warehouse = Warehouse::query()->findOrFail($data->warehouseId);

        if ($supplier !== null && ! $supplier->status) {
            throw ValidationException::withMessages(['supplier_id' => 'The selected Supplier must be active.']);
        }

        if (! $warehouse->status) {
            throw ValidationException::withMessages(['warehouse_id' => 'New Draft Purchases require an active Warehouse.']);
        }

        $productIds = collect($data->items)->map(fn (PurchaseItemData $item): int => $item->productId);

        if ($productIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'A Product may appear only once on a Purchase.']);
        }

        $products = Product::query()->whereKey($productIds)->get()->keyBy('id');

        if ($products->count() !== $productIds->count() || $products->contains(fn (Product $product): bool => $product->status !== ProductStatus::Active)) {
            throw ValidationException::withMessages(['items' => 'Every Purchase line requires an active Product.']);
        }

        if ($actor !== null && $this->responsibilities->requiresScope($actor)) {
            $outsideScope = $productIds->first(fn (int $productId): bool => ! $this->responsibilities->canAccessProduct(
                $actor,
                $productId,
                $warehouse->marketplace_platform_id,
                $data->warehouseId,
            ));

            if ($outsideScope !== null) {
                throw ValidationException::withMessages(['items' => 'Every Purchase line must be within your active Responsibility scope.']);
            }
        }

        $lines = array_map(fn (PurchaseItemData $item): array => $this->totals->line($item), $data->items);
        $documentTotals = $this->totals->document($lines, $data->shippingTotal, $data->shippingVatRate, $data->otherChargesTotal, $data->otherChargesVatRate);
        $originalInvoice = $data->supplierInvoiceNumber === null || trim($data->supplierInvoiceNumber) === '' ? null : trim($data->supplierInvoiceNumber);
        $normalized = $this->invoices->normalize($originalInvoice);

        if ($data->supplierId !== null && $normalized !== null && $conflict = $this->invoices->conflictingPurchase($data->supplierId, $normalized, $purchase?->id)) {
            throw new DuplicateSupplierInvoiceException("Supplier invoice conflicts with Purchase {$conflict->reference}.");
        }

        $externalAccountingReference = $data->externalAccountingReference === null || trim($data->externalAccountingReference) === ''
            ? null
            : trim($data->externalAccountingReference);

        return [
            'header' => array_merge([
                'supplier_id' => $data->supplierId,
                'warehouse_id' => $data->warehouseId,
                'supplier_invoice_number' => $originalInvoice,
                'supplier_invoice_number_normalized' => $normalized,
                'supplier_invoice_date' => $data->supplierInvoiceDate,
                'purchase_date' => $data->purchaseDate,
                'expected_delivery_date' => $data->expectedDeliveryDate,
                'external_accounting_reference' => $externalAccountingReference,
                'currency' => 'AED',
                'notes' => $data->notes === null ? null : trim($data->notes),
            ], $documentTotals),
            'lines' => $lines,
        ];
    }
}
