<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\PurchaseStatus;
use App\Exceptions\DuplicatePurchaseReceiptException;
use App\Exceptions\InvalidPurchaseTransitionException;
use App\Exceptions\OverReceiptException;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Inventory\DamagedStockEventService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Posts an already-authorized receipt inside the caller's transaction. */
class PurchaseReceiptPostingService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DamagedStockEventService $damagedStock,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<int, string>  $movementReferences
     * @param  array<int, string>  $damageReferences
     */
    public function post(
        Purchase $purchase,
        ReceivePurchaseData $data,
        User $actor,
        string $receiptReference,
        array $movementReferences,
        array $damageReferences = [],
    ): PurchaseReceipt {
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('Purchase receipt posting requires an active database transaction.');
        }

        if (! $purchase->status->isOpenForReceiving()) {
            throw new InvalidPurchaseTransitionException('Only Approved or Partially Received Purchases can receive stock.');
        }

        if (! $purchase->warehouse()->where('status', true)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => 'The Purchase Warehouse must be active before receiving.']);
        }

        if ($existing = PurchaseReceipt::query()->where('idempotency_key', $data->idempotencyKey)->lockForUpdate()->first()) {
            if ($existing->purchase_id !== $purchase->id) {
                throw new DuplicatePurchaseReceiptException('The GRN idempotency key belongs to another Purchase.');
            }

            return $existing->load('items');
        }

        $requested = collect($data->items)->keyBy(fn (PurchaseReceiptItemData $item): int => $item->purchaseItemId);
        $purchaseItems = PurchaseItem::query()
            ->where('purchase_id', $purchase->id)
            ->whereKey($requested->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($purchaseItems->count() !== $requested->count()) {
            throw ValidationException::withMessages(['items' => 'One or more GRN lines do not belong to this Purchase.']);
        }

        $movementGroup = (string) Str::uuid();
        $receipt = PurchaseReceipt::query()->create([
            'reference' => $receiptReference,
            'purchase_id' => $purchase->id,
            'warehouse_id' => $purchase->warehouse_id,
            'supplier_delivery_note' => blank($data->supplierDeliveryNote) ? null : trim($data->supplierDeliveryNote),
            'received_at' => $data->receivedAt,
            'received_by_user_id' => $actor->id,
            'idempotency_key' => $data->idempotencyKey,
            'movement_group' => $movementGroup,
            'notes' => blank($data->notes) ? null : trim($data->notes),
            'created_at' => now(),
        ]);
        $accepted = $damaged = $rejected = 0;
        $inactiveProductIds = [];

        foreach ($purchaseItems->sortBy([['product_id', 'asc'], ['id', 'asc']]) as $purchaseItem) {
            $request = $requested[$purchaseItem->id];
            $valuationQuantity = $request->acceptedQuantity + $request->damagedQuantity;

            if ($valuationQuantity > $purchaseItem->outstandingQuantity()) {
                throw new OverReceiptException("Receipt quantity exceeds outstanding quantity for Purchase Item {$purchaseItem->id}.");
            }

            if (! $purchaseItem->product()->where('status', 'active')->exists()) {
                $inactiveProductIds[] = $purchaseItem->product_id;
            }

            $receiptItem = $receipt->items()->create([
                'purchase_item_id' => $purchaseItem->id,
                'product_id' => $purchaseItem->product_id,
                'quantity_received' => $valuationQuantity + $request->rejectedQuantity,
                'accepted_quantity' => $request->acceptedQuantity,
                'damaged_quantity' => $request->damagedQuantity,
                'rejected_quantity' => $request->rejectedQuantity,
                'inventory_unit_cost' => $purchaseItem->inventory_unit_cost,
                'posting_key' => (string) Str::uuid(),
                'notes' => blank($request->notes) ? null : trim($request->notes),
                'created_at' => now(),
            ]);

            if ($valuationQuantity > 0) {
                $movementReference = $movementReferences[$purchaseItem->id] ?? null;

                if ($movementReference === null) {
                    throw new RuntimeException("Missing Stock Movement reference for Purchase Item {$purchaseItem->id}.");
                }

                $movement = $this->inventory->receivePurchaseItem($receiptItem, $actor, $movementReference, $movementGroup);
                if ($request->damagedQuantity > 0) {
                    $damageReference = $damageReferences[$purchaseItem->id] ?? null;
                    if ($damageReference === null || $movement === null) {
                        throw new RuntimeException("Missing Damaged Item reference for Purchase Item {$purchaseItem->id}.");
                    }
                    $this->damagedStock->recordSupplierReceiptDamage(
                        $receiptItem,
                        $movement->inventory()->firstOrFail(),
                        $actor,
                        $damageReference,
                    );
                }
            }

            $purchaseItem->forceFill([
                'received_quantity' => $purchaseItem->received_quantity + $valuationQuantity,
                'rejected_quantity' => $purchaseItem->rejected_quantity + $request->rejectedQuantity,
            ])->save();
            $accepted += $request->acceptedQuantity;
            $damaged += $request->damagedQuantity;
            $rejected += $request->rejectedQuantity;
        }

        $allReceived = ! PurchaseItem::query()->where('purchase_id', $purchase->id)
            ->whereColumn('received_quantity', '<', 'ordered_quantity')->exists();
        $anyReceived = PurchaseItem::query()->where('purchase_id', $purchase->id)
            ->where('received_quantity', '>', 0)->exists();
        $purchase->forceFill([
            'status' => $allReceived ? PurchaseStatus::FullyReceived : ($anyReceived ? PurchaseStatus::PartiallyReceived : PurchaseStatus::Approved),
        ])->save();
        $this->activity->log('purchase.received', $actor, $receipt, [
            'purchase_reference' => $purchase->reference,
            'grn_reference' => $receipt->reference,
            'entry_type' => $purchase->entry_type?->value ?? 'standard',
            'line_count' => $purchaseItems->count(),
            'accepted_quantity' => $accepted,
            'damaged_quantity' => $damaged,
            'rejected_quantity' => $rejected,
            'inactive_product_ids' => $inactiveProductIds,
            'to_status' => $purchase->status->value,
        ]);

        return $receipt->load('items');
    }
}
