<?php

namespace App\Services\Orders;

use App\Models\OrderLineFulfillment;
use App\Models\OrderLineFulfillmentEvent;
use App\Models\OrderItem;
use App\Models\AccountingObligation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderLineFulfillmentService
{
    public function recordSupplierDirectDelivery(OrderItem $item, int $quantity, User $actor, string $idempotencyKey, ?string $externalReference = null, ?array $evidence = null): OrderLineFulfillment
    {
        if ($quantity < 1) throw ValidationException::withMessages(['quantity' => 'Delivery quantity must be positive.']);

        return DB::transaction(function () use ($item, $quantity, $actor, $idempotencyKey, $externalReference, $evidence): OrderLineFulfillment {
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $lineKey = $this->lineKey($item->id);
            $line = OrderLineFulfillment::query()->firstOrCreate(['idempotency_key' => $lineKey], [
                'order_id' => $item->order_id, 'order_item_id' => $item->id, 'source_type' => 'supplier_direct',
                'requested_quantity' => $item->ordered_quantity, 'status' => 'pending', 'accounting_status' => 'pending',
            ]);
            $existing = $line->events()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $line->refresh();
            $remaining = (int) $line->requested_quantity - (int) $line->fulfilled_quantity;
            if ($remaining < 1) return $line->refresh();
            $quantity = min($quantity, $remaining);
            OrderLineFulfillmentEvent::query()->create([
                'fulfillment_id' => $line->id, 'event_type' => 'delivered', 'quantity' => $quantity,
                'external_reference' => $externalReference, 'evidence' => $evidence,
                'recorded_by_user_id' => $actor->id, 'idempotency_key' => $idempotencyKey, 'occurred_at' => now(),
            ]);
            $total = (int) $line->events()->where('event_type', 'delivered')->sum('quantity');
            $line->forceFill(['fulfilled_quantity' => $total, 'status' => $total >= $line->requested_quantity ? 'fulfilled' : 'partial'])->save();
            AccountingObligation::query()->firstOrCreate(['source_type' => 'order_line_fulfillment', 'source_id' => $line->id], [
                'reference' => 'supplier-direct:'.$item->order_id.':'.$item->id,
                'quantity' => $total,
                'unit_cost' => null,
                'currency' => null,
                'status' => 'pending',
                'metadata' => ['order_id' => $item->order_id, 'order_item_id' => $item->id, 'source' => 'supplier_direct'],
                'created_by_user_id' => $actor->id,
            ]);
            return $line->refresh();
        }, 5);
    }

    /**
     * Record real supplier purchasing and physical customer-direct shipment,
     * without inventing a warehouse movement or a duplicate AP obligation.
     */
    public function recordManualShipment(\App\Models\WebSalesManualSourcingLine $manual, User $actor): OrderLineFulfillment
    {
        if (DB::transactionLevel() < 1 || ! $manual->auto_purchase_on_ship
            || $manual->purchase_id === null || $manual->purchase_receipt_id === null
            || $manual->shipment_posted_at === null) {
            throw ValidationException::withMessages(['line' => 'A posted automatic Purchase and direct GRN are required before shipment ledger posting.']);
        }

        $item = $manual->orderItem;
        $line = OrderLineFulfillment::query()->firstOrCreate(['idempotency_key' => $this->lineKey($item->id)], [
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'source_type' => 'manual_sourced_direct',
            'requested_quantity' => $item->ordered_quantity,
            'fulfilled_quantity' => $item->ordered_quantity,
            'status' => 'fulfilled',
            'purchase_id' => $manual->purchase_id,
            'purchase_receipt_id' => $manual->purchase_receipt_id,
            'supplier_id' => $manual->supplier_id,
            'unit_cost' => $manual->inventory_unit_cost_aed,
            'currency' => 'AED',
            'accounting_status' => 'pending',
        ]);
        if ($line->source_type !== 'manual_sourced_direct'
            || (int) $line->purchase_id !== (int) $manual->purchase_id
            || (int) $line->fulfilled_quantity !== (int) $item->ordered_quantity) {
            throw ValidationException::withMessages(['line' => 'An incompatible fulfillment ledger already exists for this order item.']);
        }
        $eventKey = $this->lineKey($item->id + 800000000);
        $line->events()->firstOrCreate(['idempotency_key' => $eventKey], [
            'event_type' => 'shipped',
            'quantity' => $item->ordered_quantity,
            'external_reference' => $manual->purchase->reference,
            'evidence' => ['grn_id' => $manual->purchase_receipt_id, 'physical_warehouse_custody' => false],
            'recorded_by_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);
        AccountingObligation::query()->firstOrCreate(
            ['source_type' => 'purchase', 'source_id' => $manual->purchase_id],
            [
                'reference' => $manual->purchase->reference,
                'quantity' => $item->ordered_quantity,
                'unit_cost' => $manual->inventory_unit_cost_aed,
                'currency' => 'AED',
                'status' => 'pending',
                'metadata' => [
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'grn_id' => $manual->purchase_receipt_id,
                    'supplier_id' => $manual->supplier_id,
                    'supplier_currency' => $manual->supplier_currency,
                    'supplier_unit_cost' => $manual->supplier_unit_cost,
                    'fx_rate_to_aed' => $manual->fx_rate_to_aed,
                    'physical_warehouse_custody' => false,
                ],
                'created_by_user_id' => $actor->id,
            ]
        );

        return $line;
    }

    private function lineKey(int $itemId): string
    {
        $hex = substr(hash('sha256', 'order-line-fulfillment:'.$itemId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
