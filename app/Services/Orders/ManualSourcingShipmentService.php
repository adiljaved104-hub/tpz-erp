<?php

namespace App\Services\Orders;

use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\Enums\ManualSourcingStatus;
use App\Enums\PurchaseEntryType;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Models\ManualSourcingShipmentPosting;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Purchases\PurchaseDocumentService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Posts supplier-to-customer sourcing without creating warehouse stock. */
class ManualSourcingShipmentService
{
    public function __construct(
        private readonly PurchaseAuthorization $purchases,
        private readonly PurchaseDocumentService $documents,
        private readonly ReferenceSequenceService $references,
        private readonly InventoryBalanceService $balances,
        private readonly ActivityLogger $activity,
        private readonly OrderLineFulfillmentService $lineFulfillments,
    ) {}

    /** @return array<int,array{purchase_reference:string,receipt_reference:string,movement_references:array<int,string>}> */
    public function preallocateReferences(Order $order, string $shipmentKey): array
    {
        $references = [];
        foreach ($order->manualSourcingLines()->where('status', '!=', ManualSourcingStatus::Fulfilled->value)->orderBy('id')->get() as $line) {
            $postingKey = $this->postingKey($shipmentKey, (int) $line->id);
            if (ManualSourcingShipmentPosting::query()->where('idempotency_key', $postingKey)->exists()) {
                continue;
            }
            $references[$line->id] = [
                'purchase_reference' => $this->references->nextPurchaseReference(),
                'receipt_reference' => $this->references->nextPurchaseReceiptReference(),
                'movement_references' => [
                    $this->references->nextStockMovementReference(),
                    $this->references->nextStockMovementReference(),
                ],
            ];
        }

        return $references;
    }

    public function materialize(Order $order, string $shipmentKey, User $actor, ?array $preallocatedReferences = null): void
    {
        $preallocatedReferences ??= $this->preallocateReferences($order, $shipmentKey);
        DB::transaction(function () use ($order, $shipmentKey, $actor, $preallocatedReferences): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            foreach ($locked->manualSourcingLines()->lockForUpdate()->get() as $line) {
                if ($line->status === ManualSourcingStatus::Fulfilled) {
                    continue;
                }
                $postingKey = $this->postingKey($shipmentKey, (int) $line->id);
                if (ManualSourcingShipmentPosting::query()->where('idempotency_key', $postingKey)->lockForUpdate()->exists()) {
                    continue;
                }
                if ($line->source_mode !== 'supplier_direct') {
                    throw ValidationException::withMessages(['items' => 'Only supplier-to-customer manual sourcing is materialized during shipment.']);
                }
                if ($line->actual_unit_cost === null || blank($line->purchase_currency) || $line->purchased_on === null) {
                    throw ValidationException::withMessages(['items' => 'Manual sourced lines require actual purchase cost, currency, and purchase date before shipment.']);
                }
                if (strtoupper((string) $line->purchase_currency) !== 'AED') {
                    throw ValidationException::withMessages(['items' => 'The current purchasing ledger supports AED only.']);
                }
                $this->purchases->authorize($actor, PurchasePermission::QuickReceive);
                $refs = $preallocatedReferences[$line->id] ?? null;
                if ($refs === null) {
                    throw ValidationException::withMessages(['items' => 'Shipment references could not be prepared for every manual sourcing line. Please retry.']);
                }
                $purchase = $this->createPurchase($locked, $line, $postingKey, $actor, $refs['purchase_reference']);
                $receipt = $this->createDirectReceipt($purchase, $locked, $postingKey, $actor, $refs['receipt_reference']);
                $this->recordDirectMovements($receipt, $locked, $actor, $postingKey, $refs['movement_references']);
                $posting = ManualSourcingShipmentPosting::query()->create([
                    'sourcing_line_id' => $line->id, 'order_id' => $locked->id, 'order_item_id' => $line->order_item_id,
                    'purchase_id' => $purchase->id, 'purchase_receipt_id' => $receipt->id, 'quantity' => $line->quantity,
                    'idempotency_key' => $postingKey, 'custody_mode' => 'direct_to_customer', 'posted_at' => now(), 'posted_by_user_id' => $actor->id,
                ]);
                $this->lineFulfillments->recordSupplierDirectDelivery(
                    $locked->items()->whereKey($line->order_item_id)->firstOrFail(),
                    (int) $line->quantity,
                    $actor,
                    $postingKey,
                    'manual-sourcing:'.$purchase->reference,
                    ['purchase_id' => $purchase->id, 'receipt_id' => $receipt->id],
                    (string) $line->actual_unit_cost,
                    strtoupper((string) $line->purchase_currency),
                    ['custody_mode' => 'direct_to_customer'],
                );
                $line->forceFill(['purchase_id' => $purchase->id, 'purchase_receipt_id' => $receipt->id, 'status' => ManualSourcingStatus::ReadyForAllocation, 'receipt_confirmed_at' => now(), 'metadata' => array_merge($line->metadata ?? [], ['direct_fulfillment' => true, 'shipment_key' => $shipmentKey])])->save();
            }
        }, 5);
    }

    private function createPurchase(Order $order, object $line, string $idempotencyKey, User $actor, string $reference): Purchase
    {
        $data = new CreatePurchaseData(
            supplierId: $line->supplier_id === null ? null : (int) $line->supplier_id, warehouseId: (int) $order->warehouse_id, purchaseDate: $line->purchased_on->toDateString(),
            items: [new PurchaseItemData((int) $line->product_id, (int) $line->quantity, (string) $line->actual_unit_cost)],
            notes: 'Direct fulfilment for Web Sales order '.$order->reference
                .($line->supplier_id === null
                    ? (filled($line->metadata['supplier_name'] ?? null)
                        ? '. Supplier name provided at sale: '.(string) $line->metadata['supplier_name']
                        : '. Supplier not specified at sale.')
                    : ''),
            handledByEmployeeId: $actor->employee?->id,
        );
        $prepared = $this->documents->prepare($data, actor: $actor, receivingScope: true);
        $purchase = Purchase::query()->create(array_merge($prepared['header'], [
            'reference' => $reference, 'entry_type' => PurchaseEntryType::QuickStock,
            'handled_by_employee_id' => $actor->employee?->id, 'status' => PurchaseStatus::Approved, 'created_by_user_id' => $actor->id,
            'approved_by_user_id' => $actor->id, 'approved_at' => now(), 'approval_reason' => 'Direct supplier-to-customer fulfilment; physical custody remains with supplier.',
            'self_approved' => true, 'external_accounting_reference' => 'manual-sourcing:'.$idempotencyKey,
        ]));
        $purchase->items()->createMany($prepared['lines']);
        $this->activity->log('purchase.created', $actor, $purchase, [
            'entry_type' => PurchaseEntryType::QuickStock->value,
            'direct_fulfillment' => true,
            'supplier_master_linked' => $purchase->supplier_id !== null,
        ]);

        return $purchase->load('items');
    }

    private function createDirectReceipt(Purchase $purchase, Order $order, string $idempotencyKey, User $actor, string $reference): PurchaseReceipt
    {
        $receipt = PurchaseReceipt::query()->create([
            'reference' => $reference, 'purchase_id' => $purchase->id, 'warehouse_id' => $purchase->warehouse_id,
            'supplier_delivery_note' => 'Direct-to-customer '.$order->reference, 'received_at' => now(), 'received_by_user_id' => $actor->id,
            'idempotency_key' => $idempotencyKey, 'movement_group' => (string) str()->uuid(), 'notes' => 'Direct-to-customer receipt; no warehouse custody.',
            'custody_mode' => 'direct_to_customer', 'direct_fulfillment_order_id' => $order->id, 'created_at' => now(),
        ]);
        $item = $purchase->items->firstOrFail();
        $receipt->items()->create([
            'purchase_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity_received' => $item->ordered_quantity,
            'accepted_quantity' => $item->ordered_quantity, 'damaged_quantity' => 0, 'rejected_quantity' => 0, 'inventory_unit_cost' => $item->inventory_unit_cost,
            'posting_key' => (string) str()->uuid(), 'notes' => 'Direct-to-customer receipt.', 'created_at' => now(),
        ]);
        $item->forceFill(['received_quantity' => $item->ordered_quantity])->save();
        $purchase->forceFill(['status' => PurchaseStatus::FullyReceived])->save();

        return $receipt->load('items');
    }

    /** @param array<int,string> $movementReferences */
    private function recordDirectMovements(PurchaseReceipt $receipt, Order $order, User $actor, string $idempotencyKey, array $movementReferences): void
    {
        $receiptItem = $receipt->items->firstOrFail();
        $inventory = $this->balances->lockOrCreate($receiptItem->product_id, $receipt->warehouse_id);
        $before = ['available' => $inventory->available_quantity, 'reserved' => $inventory->reserved_quantity, 'damaged' => $inventory->damaged_quantity, 'average' => $inventory->average_cost];
        foreach ([StockMovementType::PurchaseReceipt, StockMovementType::OrderFulfillment] as $index => $type) {
            StockMovement::query()->create([
                'reference' => $movementReferences[$index], 'movement_group' => $receipt->movement_group, 'idempotency_key' => $this->movementKey($idempotencyKey, $index),
                'product_inventory_id' => $inventory->id, 'product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id, 'movement_type' => $type,
                'quantity' => $receiptItem->accepted_quantity, 'available_delta' => 0, 'reserved_delta' => 0, 'damaged_delta' => 0,
                'available_before' => $before['available'], 'available_after' => $before['available'], 'reserved_before' => $before['reserved'], 'reserved_after' => $before['reserved'],
                'damaged_before' => $before['damaged'], 'damaged_after' => $before['damaged'], 'unit_cost' => $receiptItem->inventory_unit_cost,
                'average_cost_before' => $before['average'], 'average_cost_after' => $before['average'], 'source_type' => $index === 0 ? $receiptItem->getMorphClass() : $order->getMorphClass(),
                'source_id' => $index === 0 ? $receiptItem->id : $order->id, 'reason' => $index === 0 ? 'Direct supplier-to-customer receipt (no warehouse custody).' : 'Direct supplier-to-customer consumption.',
                'actor_user_id' => $actor->id, 'occurred_at' => now(),
            ]);
        }
    }

    private function postingKey(string $shipmentKey, int $lineId): string
    {
        $hex = substr(hash('sha256', 'manual-sourcing-shipment:'.$shipmentKey.':'.$lineId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function movementKey(string $postingKey, int $index): string
    {
        $hex = substr(hash('sha256', 'manual-sourcing-movement:'.$postingKey.':'.$index), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
