<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\PurchasePermission;
use App\Events\PurchaseReceived;
use App\Exceptions\DuplicatePurchaseReceiptException;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchaseReceivingService
{
    public function __construct(
        private readonly PurchaseAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly PurchaseReceiptPostingService $posting,
    ) {}

    public function receive(Purchase $purchase, ReceivePurchaseData $data, User $actor): PurchaseReceipt
    {
        $this->authorization->authorize($actor, PurchasePermission::Receive, $purchase);
        $this->validate($data);

        if ($existing = PurchaseReceipt::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            if ($existing->purchase_id !== $purchase->id) {
                throw new DuplicatePurchaseReceiptException('The GRN idempotency key belongs to another Purchase.');
            }

            return $existing->load('items');
        }

        $receiptReference = $this->references->nextPurchaseReceiptReference();
        $movementReferences = [];
        $damageReferences = [];

        foreach ($data->items as $item) {
            if ($item->acceptedQuantity + $item->damagedQuantity > 0) {
                $movementReferences[$item->purchaseItemId] = $this->references->nextStockMovementReference();
            }
            if ($item->damagedQuantity > 0) {
                $damageReferences[$item->purchaseItemId] = $this->references->nextDamagedStockReference();
            }
        }

        $receipt = DB::transaction(function () use ($purchase, $data, $actor, $receiptReference, $movementReferences, $damageReferences): PurchaseReceipt {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->authorization->authorize($actor, PurchasePermission::Receive, $purchase);

            return $this->posting->post($purchase, $data, $actor, $receiptReference, $movementReferences, $damageReferences);
        }, 5);

        PurchaseReceived::dispatch($receipt);

        return $receipt;
    }

    private function validate(ReceivePurchaseData $data): void
    {
        Validator::make([
            'received_at' => $data->receivedAt,
            'idempotency_key' => $data->idempotencyKey,
            'items' => $data->items,
            'supplier_delivery_note' => $data->supplierDeliveryNote,
            'notes' => $data->notes,
        ], [
            'received_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'supplier_delivery_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $itemIds = collect($data->items)->map(fn (PurchaseReceiptItemData $item): int => $item->purchaseItemId);

        if ($itemIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'A Purchase line may appear only once on a GRN.']);
        }

        foreach ($data->items as $item) {
            if ($item->acceptedQuantity < 0 || $item->damagedQuantity < 0 || $item->rejectedQuantity < 0
                || $item->acceptedQuantity + $item->damagedQuantity + $item->rejectedQuantity <= 0) {
                throw ValidationException::withMessages(['items' => 'Every GRN line requires positive physical quantity and non-negative classifications.']);
            }
        }
    }
}
