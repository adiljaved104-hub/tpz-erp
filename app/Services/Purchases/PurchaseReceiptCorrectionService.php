<?php

namespace App\Services\Purchases;

use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Exceptions\InventoryInvariantException;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceiptCorrection;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptCorrectionService
{
    public function __construct(
        private readonly PurchaseAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly InventoryService $inventory,
        private readonly InventoryAllocationService $allocation,
        private readonly ActivityLogger $activity,
    ) {}

    public function correct(
        PurchaseReceiptItem $target,
        int $correctedQuantity,
        string $reason,
        string $idempotencyKey,
        User $actor,
    ): PurchaseReceiptCorrection {
        $target->loadMissing('receipt.purchase');
        $purchase = $target->receipt->purchase;
        $this->authorization->authorize($actor, PurchasePermission::ViewReceipts, $purchase);
        $this->authorization->authorize($actor, PurchasePermission::CorrectReceipt, $purchase);

        $validated = Validator::make([
            'corrected_quantity' => $correctedQuantity,
            'reason' => trim($reason),
            'idempotency_key' => $idempotencyKey,
        ], [
            'corrected_quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        if ($existing = PurchaseReceiptCorrection::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $this->validateReplay($existing, $target, $validated['corrected_quantity']);
        }

        $correctionReference = $this->references->nextPurchaseReceiptCorrectionReference();
        $movementReference = $this->references->nextStockMovementReference();
        $movementGroup = (string) Str::uuid();

        return DB::transaction(function () use ($target, $validated, $actor, $correctionReference, $movementReference, $movementGroup): PurchaseReceiptCorrection {
            if ($existing = PurchaseReceiptCorrection::query()->where('idempotency_key', $validated['idempotency_key'])->lockForUpdate()->first()) {
                return $this->validateReplay($existing, $target, $validated['corrected_quantity']);
            }

            $lockedTarget = PurchaseReceiptItem::query()->with('receipt')->lockForUpdate()->findOrFail($target->id);
            $purchaseItem = PurchaseItem::query()->lockForUpdate()->findOrFail($lockedTarget->purchase_item_id);
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($lockedTarget->receipt->purchase_id);
            $priorReduction = (int) PurchaseReceiptCorrection::query()
                ->where('purchase_receipt_item_id', $lockedTarget->id)
                ->selectRaw('COALESCE(SUM(-adjustment_quantity), 0) AS aggregate')
                ->value('aggregate');
            $effectiveQuantity = $lockedTarget->accepted_quantity - $priorReduction;
            $correctedQuantity = $validated['corrected_quantity'];

            if ($correctedQuantity > $effectiveQuantity) {
                throw ValidationException::withMessages([
                    'corrected_quantity' => 'Use normal Purchase Receiving for additional genuine stock.',
                ]);
            }
            if ($correctedQuantity === $effectiveQuantity) {
                throw ValidationException::withMessages([
                    'corrected_quantity' => 'Correct quantity must be lower than the current effective received quantity.',
                ]);
            }

            $adjustment = $correctedQuantity - $effectiveQuantity;
            $correction = PurchaseReceiptCorrection::query()->create([
                'reference' => $correctionReference,
                'purchase_id' => $purchase->id,
                'purchase_receipt_id' => $lockedTarget->purchase_receipt_id,
                'purchase_receipt_item_id' => $lockedTarget->id,
                'purchase_item_id' => $purchaseItem->id,
                'product_id' => $lockedTarget->product_id,
                'warehouse_id' => $lockedTarget->receipt->warehouse_id,
                'original_received_quantity' => $lockedTarget->accepted_quantity,
                'quantity_before' => $effectiveQuantity,
                'corrected_quantity' => $correctedQuantity,
                'adjustment_quantity' => $adjustment,
                'inventory_unit_cost' => $lockedTarget->inventory_unit_cost,
                'reason' => $validated['reason'],
                'performed_by_user_id' => $actor->id,
                'corrected_at' => now(),
                'idempotency_key' => $validated['idempotency_key'],
                'movement_group' => $movementGroup,
                'created_at' => now(),
            ]);

            $inventory = ProductInventory::query()
                ->where('product_id', $correction->product_id)
                ->where('warehouse_id', $correction->warehouse_id)
                ->lockForUpdate()
                ->firstOrFail();

            try {
                $allocationDetails = $this->allocation->reverseReceiptCorrection($correction, $inventory, $actor);
                $this->inventory->correctPurchaseReceipt($correction, $actor, $movementReference);
            } catch (InventoryInvariantException $exception) {
                throw ValidationException::withMessages(['corrected_quantity' => $exception->getMessage()]);
            }

            $newReceived = $purchaseItem->received_quantity + $adjustment;
            if ($newReceived < 0) {
                throw ValidationException::withMessages(['corrected_quantity' => 'The correction would make the effective Purchase receipt quantity invalid.']);
            }
            $purchaseItem->forceFill(['received_quantity' => $newReceived])->save();
            $this->refreshPurchaseStatus($purchase);

            $this->activity->log('purchase_receipt.corrected', $actor, $correction, [
                'purchase_reference' => $purchase->reference,
                'grn_reference' => $lockedTarget->receipt->reference,
                'correction_reference' => $correction->reference,
                'product_id' => $correction->product_id,
                'quantity_before' => $effectiveQuantity,
                'quantity_after' => $correctedQuantity,
                'adjustment' => $adjustment,
                'reason_recorded' => true,
                'allocation_reversals' => $allocationDetails,
            ]);

            return $correction->load('allocationLines');
        }, 5);
    }

    private function validateReplay(PurchaseReceiptCorrection $existing, PurchaseReceiptItem $target, int $correctedQuantity): PurchaseReceiptCorrection
    {
        if ($existing->purchase_receipt_item_id !== $target->id || $existing->corrected_quantity !== $correctedQuantity) {
            throw ValidationException::withMessages(['idempotency_key' => 'This correction submission was already used for a different request.']);
        }

        return $existing;
    }

    private function refreshPurchaseStatus(Purchase $purchase): void
    {
        $allReceived = ! PurchaseItem::query()->where('purchase_id', $purchase->id)
            ->whereColumn('received_quantity', '<', 'ordered_quantity')->exists();
        $anyReceived = PurchaseItem::query()->where('purchase_id', $purchase->id)
            ->where('received_quantity', '>', 0)->exists();
        $purchase->forceFill([
            'status' => $allReceived ? PurchaseStatus::FullyReceived : ($anyReceived ? PurchaseStatus::PartiallyReceived : PurchaseStatus::Approved),
        ])->save();
    }
}
