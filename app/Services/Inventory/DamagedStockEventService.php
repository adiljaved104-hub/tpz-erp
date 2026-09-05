<?php

namespace App\Services\Inventory;

use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Models\CustomerReturnInspection;
use App\Models\DamagedStockEvent;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Claims\SafetClaimService;

class DamagedStockEventService
{
    public function __construct(private readonly ActivityLogger $activity, private readonly SafetClaimService $claims) {}

    public function recordReturnQcDamage(
        CustomerReturnInspection $inspection,
        ProductInventory $inventory,
        User $actor,
        string $reference,
        ?string $claimReference = null,
    ): DamagedStockEvent {
        if ($existing = DamagedStockEvent::query()->where('idempotency_key', $inspection->posting_key)->first()) {
            return $existing;
        }

        $returnItem = $inspection->item;
        $removalItem = $inspection->removalItem;
        $return = $returnItem?->customerReturn;
        $removal = $removalItem?->removal;
        $linkedReturnItem = $returnItem ?? $removalItem?->returnItem;
        $return ??= $linkedReturnItem?->customerReturn;

        $event = DamagedStockEvent::query()->create([
            'reference' => $reference,
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => $inspection->quantity,
            'source' => DamagedStockSource::CustomerReturn,
            'source_type' => $inspection->getMorphClass(),
            'source_id' => $inspection->id,
            'marketplace_platform_id' => $return?->marketplace_platform_id ?? $removal?->marketplace_platform_id,
            'customer_return_id' => $return?->id,
            'customer_return_item_id' => $linkedReturnItem?->id,
            'customer_return_inspection_id' => $inspection->id,
            'order_id' => $return?->order_id,
            'reason' => 'Return QC classified as damaged',
            'notes' => $inspection->notes,
            'occurred_at' => $inspection->inspected_at,
            'reported_by_user_id' => $actor->id,
            'status' => DamagedStockStatus::Damaged,
            'idempotency_key' => $inspection->posting_key,
        ]);

        $this->activity->log('damaged_stock.created', $actor, $event, [
            'damage_reference' => $reference,
            'product_id' => $inventory->product_id,
            'quantity' => $inspection->quantity,
            'source' => DamagedStockSource::CustomerReturn->value,
        ]);

        if ($linkedReturnItem !== null) {
            $this->claims->createFromQcDamage($event, $linkedReturnItem, $actor, $claimReference);
        }

        return $event;
    }

    public function recordSupplierReceiptDamage(
        PurchaseReceiptItem $receiptItem,
        ProductInventory $inventory,
        User $actor,
        string $reference,
    ): DamagedStockEvent {
        if ($existing = DamagedStockEvent::query()->where('idempotency_key', $receiptItem->posting_key)->first()) {
            return $existing;
        }

        $receiptItem->loadMissing('receipt');
        $event = DamagedStockEvent::query()->create([
            'reference' => $reference,
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => $receiptItem->damaged_quantity,
            'source' => DamagedStockSource::SupplierReceipt,
            'source_type' => $receiptItem->getMorphClass(),
            'source_id' => $receiptItem->id,
            'reason' => 'Supplier receipt classified as damaged',
            'notes' => $receiptItem->notes,
            'occurred_at' => $receiptItem->receipt->received_at,
            'reported_by_user_id' => $actor->id,
            'status' => DamagedStockStatus::Damaged,
            'idempotency_key' => $receiptItem->posting_key,
        ]);

        $this->activity->log('damaged_stock.created', $actor, $event, [
            'damage_reference' => $reference,
            'product_id' => $inventory->product_id,
            'quantity' => $receiptItem->damaged_quantity,
            'source' => DamagedStockSource::SupplierReceipt->value,
        ]);

        return $event;
    }
}
