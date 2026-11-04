<?php

namespace App\Services\Orders;

use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\Enums\ManualSourcingStatus;
use App\Models\ManualSourcingShipmentPosting;
use App\Models\Order;
use App\Models\User;
use App\Services\Purchases\QuickStockPurchaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Atomically materialises a manual-sourced line at shipment time. */
class ManualSourcingShipmentService
{
    public function __construct(private readonly QuickStockPurchaseService $purchases) {}

    public function materialize(Order $order, string $shipmentKey, User $actor): void
    {
        DB::transaction(function () use ($order, $shipmentKey, $actor): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            foreach ($locked->manualSourcingLines()->lockForUpdate()->get() as $line) {
                if ($line->status === ManualSourcingStatus::Fulfilled) {
                    continue;
                }
                $postingKey = $this->postingKey($shipmentKey, (int) $line->id);
                if (ManualSourcingShipmentPosting::query()->where('idempotency_key', $postingKey)->exists()) {
                    continue;
                }
                if ($line->supplier_id === null || $line->actual_unit_cost === null || blank($line->purchase_currency) || $line->purchased_on === null) {
                    throw ValidationException::withMessages(['items' => 'Manual sourced lines require supplier, actual purchase cost, currency, and purchase date before shipment.']);
                }
                if ($line->purchase_currency !== 'AED') {
                    throw ValidationException::withMessages(['items' => 'Only AED supplier purchases are supported by the existing purchasing ledger.']);
                }
                $purchaseKey = $this->purchaseKey($postingKey);
                $result = $this->purchases->createAndReceive(new QuickStockPurchaseData(
                    warehouseId: (int) $locked->warehouse_id,
                    purchaseDate: $line->purchased_on->toDateString(),
                    items: [new PurchaseItemData((int) $line->product_id, (int) $line->quantity, (string) $line->actual_unit_cost)],
                    idempotencyKey: $purchaseKey,
                    supplierId: (int) $line->supplier_id,
                    handledByEmployeeId: $actor->employee?->id,
                    notes: 'Direct fulfilment for Web Sales order '.$locked->reference,
                ), $actor);
                $receipt = $result->receipt->fresh('purchase');
                $receipt->forceFill(['custody_mode' => 'direct_to_customer', 'direct_fulfillment_order_id' => $locked->id])->save();
                $line->forceFill([
                    'purchase_id' => $receipt->purchase_id,
                    'purchase_receipt_id' => $receipt->id,
                    'status' => ManualSourcingStatus::ReadyForAllocation,
                    'receipt_confirmed_at' => now(),
                    'metadata' => array_merge($line->metadata ?? [], ['direct_fulfillment' => true, 'shipment_key' => $shipmentKey]),
                ])->save();
                ManualSourcingShipmentPosting::query()->create([
                    'sourcing_line_id' => $line->id, 'order_id' => $locked->id, 'order_item_id' => $line->order_item_id,
                    'purchase_id' => $receipt->purchase_id, 'purchase_receipt_id' => $receipt->id, 'quantity' => $line->quantity,
                    'idempotency_key' => $postingKey, 'custody_mode' => 'direct_to_customer', 'posted_at' => now(), 'posted_by_user_id' => $actor->id,
                ]);
            }
        }, 5);
    }

    private function postingKey(string $shipmentKey, int $lineId): string
    {
        $hex = substr(hash('sha256', 'manual-sourcing-shipment:'.$shipmentKey.':'.$lineId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function purchaseKey(string $postingKey): string
    {
        return $postingKey;
    }
}
