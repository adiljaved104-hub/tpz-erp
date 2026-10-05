<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\DTOs\Purchases\QuickStockPurchaseResult;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\ProductStatus;
use App\Enums\PurchaseEntryType;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Events\PurchaseApproved;
use App\Events\PurchaseReceived;
use App\Exceptions\QuickStockPurchaseIdempotencyConflictException;
use App\Models\Employee;
use App\Models\InventoryAllocationAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\ResponsibilityAssignment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ReferenceSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QuickStockPurchaseService
{
    public function __construct(
        private readonly PurchaseAuthorization $authorization,
        private readonly PurchaseDocumentService $documents,
        private readonly PurchaseReceiptPostingService $posting,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function createAndReceive(QuickStockPurchaseData $data, User $actor): QuickStockPurchaseResult
    {
        $this->authorization->authorize($actor, PurchasePermission::QuickReceive);
        $this->validateQuickRules($data);

        if ($existing = $this->existingResult($data)) {
            return $existing;
        }

        $prepared = $this->documents->prepare($this->documentData($data), actor: $actor);
        $purchaseReference = $this->references->nextPurchaseReference();
        $receiptReference = $this->references->nextPurchaseReceiptReference();
        $productIds = collect($data->items)->pluck('productId')->map(fn ($id): int => (int) $id)->sort()->values();
        $movementReferencesByProduct = [];

        foreach ($productIds as $productId) {
            $movementReferencesByProduct[$productId] = $this->references->nextStockMovementReference();
        }

        try {
            $result = DB::transaction(function () use ($data, $actor, $prepared, $purchaseReference, $receiptReference, $movementReferencesByProduct): QuickStockPurchaseResult {
                $this->authorization->authorize($actor, PurchasePermission::QuickReceive);

                if ($existing = $this->existingResult($data, lock: true)) {
                    return $existing;
                }

                $this->lockAndRevalidate($data);
                if (app(OrderResponsibilityScopeService::class)->requiresScope($actor)) {
                    ResponsibilityAssignment::query()->active()->where('employee_id', $actor->employee->id)->orderBy('id')->lockForUpdate()->get();
                }
                $prepared = $this->documents->prepare($this->documentData($data), actor: $actor);
                $purchase = Purchase::query()->create(array_merge($prepared['header'], [
                    'reference' => $purchaseReference,
                    'entry_type' => PurchaseEntryType::QuickStock,
                    'handled_by_employee_id' => $data->handledByEmployeeId,
                    'status' => PurchaseStatus::Approved,
                    'created_by_user_id' => $actor->id,
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'approval_reason' => 'Quick Stock Purchase: stock physically present and received atomically.',
                    'self_approved' => true,
                ]));
                $purchase->items()->createMany($prepared['lines']);
                $purchase->load('items');

                $this->activity->log('purchase.created', $actor, $purchase, [
                    'purchase_reference' => $purchase->reference,
                    'entry_type' => PurchaseEntryType::QuickStock->value,
                    'supplier_id' => $purchase->supplier_id,
                    'warehouse_id' => $purchase->warehouse_id,
                    'handled_by_employee_id' => $purchase->handled_by_employee_id,
                    'line_count' => $purchase->items->count(),
                ]);
                $this->activity->log('purchase.approved', $actor, $purchase, [
                    'purchase_reference' => $purchase->reference,
                    'entry_type' => PurchaseEntryType::QuickStock->value,
                    'from_status' => 'created',
                    'to_status' => PurchaseStatus::Approved->value,
                    'self_approved' => true,
                ]);

                $receiptData = new ReceivePurchaseData(
                    items: $purchase->items->map(fn ($item): PurchaseReceiptItemData => new PurchaseReceiptItemData(
                        purchaseItemId: $item->id,
                        acceptedQuantity: $item->ordered_quantity,
                        damagedQuantity: 0,
                        rejectedQuantity: 0,
                        allocationAccountId: $data->allocationAccountId,
                    ))->all(),
                    receivedAt: now()->toDateTimeString(),
                    idempotencyKey: $data->idempotencyKey,
                    supplierDeliveryNote: $data->supplierDeliveryNote,
                    notes: $data->notes,
                );
                $movementReferences = $purchase->items->mapWithKeys(
                    fn ($item): array => [$item->id => $movementReferencesByProduct[$item->product_id]],
                )->all();
                $receipt = $this->posting->post($purchase, $receiptData, $actor, $receiptReference, $movementReferences);

                return new QuickStockPurchaseResult($purchase->fresh(['items', 'receipts']), $receipt);
            }, 5);
        } catch (QueryException $exception) {
            if ($existing = $this->existingResult($data)) {
                return $existing;
            }

            throw $exception;
        }

        if (! $result->replayed) {
            PurchaseApproved::dispatch($result->purchase);
            PurchaseReceived::dispatch($result->receipt);
        }

        return $result;
    }

    private function documentData(QuickStockPurchaseData $data): CreatePurchaseData
    {
        return new CreatePurchaseData(
            supplierId: $data->supplierId,
            warehouseId: $data->warehouseId,
            purchaseDate: $data->purchaseDate,
            items: $data->items,
            supplierInvoiceNumber: $data->supplierInvoiceNumber,
            supplierInvoiceDate: $data->supplierInvoiceDate,
            externalAccountingReference: $data->externalAccountingReference,
            shippingTotal: $data->shippingTotal,
            shippingVatRate: '0.00',
            otherChargesTotal: $data->otherChargesTotal,
            otherChargesVatRate: '0.00',
            notes: $data->notes,
        );
    }

    private function validateQuickRules(QuickStockPurchaseData $data): void
    {
        Validator::make([
            'idempotency_key' => $data->idempotencyKey,
            'handled_by_employee_id' => $data->handledByEmployeeId,
            'supplier_delivery_note' => $data->supplierDeliveryNote,
            'allocation_account_id' => $data->allocationAccountId,
        ], [
            'idempotency_key' => ['required', 'uuid'],
            'handled_by_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'supplier_delivery_note' => ['nullable', 'string', 'max:255'],
            'allocation_account_id' => ['nullable', 'integer', 'exists:inventory_allocation_accounts,id'],
        ])->validate();

        if ($data->handledByEmployeeId !== null && ! Employee::query()->whereKey($data->handledByEmployeeId)->where('status', true)->exists()) {
            throw ValidationException::withMessages(['handled_by_employee_id' => 'Handled By / Reported By must be an active Employee.']);
        }

        if ($data->allocationAccountId !== null && ! InventoryAllocationAccount::query()->whereKey($data->allocationAccountId)->where('status', true)->exists()) {
            throw ValidationException::withMessages(['allocation_account_id' => 'Allocate Stock To must be an active allocation account.']);
        }

        foreach ($data->items as $index => $item) {
            if (bccomp($item->lineDiscountTotal, '0.00', 2) !== 0 || bccomp($item->vatRate, '0.00', 2) !== 0) {
                throw ValidationException::withMessages(["items.{$index}" => 'Quick Stock Purchase requires zero VAT and zero line discount.']);
            }
        }
    }

    private function lockAndRevalidate(QuickStockPurchaseData $data): void
    {
        $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($data->warehouseId);

        if (! $warehouse->status) {
            throw ValidationException::withMessages(['warehouse_id' => 'Quick Stock Purchase requires an active Warehouse.']);
        }

        if ($data->supplierId !== null && ! Supplier::query()->lockForUpdate()->whereKey($data->supplierId)->where('status', true)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'The selected Supplier must be active.']);
        }

        if ($data->handledByEmployeeId !== null && ! Employee::query()->lockForUpdate()->whereKey($data->handledByEmployeeId)->where('status', true)->exists()) {
            throw ValidationException::withMessages(['handled_by_employee_id' => 'Handled By / Reported By must be an active Employee.']);
        }

        if ($data->allocationAccountId !== null && ! InventoryAllocationAccount::query()->lockForUpdate()->whereKey($data->allocationAccountId)->where('status', true)->exists()) {
            throw ValidationException::withMessages(['allocation_account_id' => 'Allocate Stock To must be an active allocation account.']);
        }

        $ids = collect($data->items)->pluck('productId')->map(fn ($id): int => (int) $id);

        if ($ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'A Product may appear only once in a Quick Stock Purchase.']);
        }

        $products = Product::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

        if ($products->count() !== $ids->count() || $products->contains(fn (Product $product): bool => $product->status !== ProductStatus::Active)) {
            throw ValidationException::withMessages(['items' => 'Every Quick Stock Purchase line requires an active Product.']);
        }
    }

    private function existingResult(QuickStockPurchaseData $data, bool $lock = false): ?QuickStockPurchaseResult
    {
        $query = PurchaseReceipt::query()->where('idempotency_key', $data->idempotencyKey);
        $receipt = ($lock ? $query->lockForUpdate() : $query)->with(['purchase.items', 'items.allocationLines'])->first();

        if ($receipt === null) {
            return null;
        }

        $purchase = $receipt->purchase;
        $expectedItems = collect($data->items)->map(fn ($item): array => [
            'product_id' => $item->productId,
            'quantity' => $item->orderedQuantity,
            'unit_cost' => bcadd($item->unitCost, '0', 4),
        ])->sortBy('product_id')->values()->all();
        $actualItems = $purchase->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'quantity' => $item->ordered_quantity,
            'unit_cost' => (string) $item->unit_cost,
        ])->sortBy('product_id')->values()->all();
        $matches = $purchase->entry_type === PurchaseEntryType::QuickStock
            && $purchase->warehouse_id === $data->warehouseId
            && $purchase->supplier_id === $data->supplierId
            && $purchase->handled_by_employee_id === $data->handledByEmployeeId
            && $purchase->purchase_date?->toDateString() === $data->purchaseDate
            && $purchase->supplier_invoice_number === $this->nullableTrim($data->supplierInvoiceNumber)
            && $purchase->supplier_invoice_date?->toDateString() === $data->supplierInvoiceDate
            && $purchase->external_accounting_reference === $this->nullableTrim($data->externalAccountingReference)
            && $receipt->supplier_delivery_note === $this->nullableTrim($data->supplierDeliveryNote)
            && (string) $purchase->shipping_total === bcadd($data->shippingTotal, '0', 2)
            && (string) $purchase->other_charges_total === bcadd($data->otherChargesTotal, '0', 2)
            && $receipt->items->every(function ($item) use ($data): bool {
                if ($data->allocationAccountId === null) {
                    return $item->allocationLines->every(
                        fn ($line): bool => $line->allocation_method !== 'grn_selected'
                    );
                }

                if ($item->allocationLines->count() !== 1) {
                    return false;
                }
                $line = $item->allocationLines->first();

                return $line->allocation_method === 'grn_selected'
                    && $line->account_id === $data->allocationAccountId;
            })
            && $expectedItems === $actualItems;

        if (! $matches) {
            throw new QuickStockPurchaseIdempotencyConflictException('This submission key was already used with different Quick Stock Purchase details.');
        }

        return new QuickStockPurchaseResult($purchase, $receipt, replayed: true);
    }

    private function nullableTrim(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }
}
