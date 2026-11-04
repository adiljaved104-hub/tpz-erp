<?php

namespace App\Services\Orders;

use App\Models\AccountingObligation;
use App\Models\OrderItem;
use App\Models\OrderLineFulfillment;
use App\Models\OrderLineFulfillmentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderLineFulfillmentService
{
    public function recordSupplierDirectDelivery(OrderItem $item, int $quantity, User $actor, string $idempotencyKey, ?string $externalReference = null, ?array $evidence = null, ?string $unitCost = null, ?string $currency = null, ?array $metadata = null): OrderLineFulfillment
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Delivery quantity must be positive.']);
        }

        return DB::transaction(function () use ($item, $quantity, $actor, $idempotencyKey, $externalReference, $evidence, $unitCost, $currency, $metadata): OrderLineFulfillment {
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $lineKey = $this->lineKey($item->id);
            $line = OrderLineFulfillment::query()->firstOrCreate(['idempotency_key' => $lineKey], [
                'order_id' => $item->order_id, 'order_item_id' => $item->id, 'source_type' => 'supplier_direct',
                'requested_quantity' => $item->ordered_quantity, 'status' => 'pending', 'accounting_status' => 'pending',
            ]);
            $existing = $line->events()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $line->refresh();
            }
            $remaining = (int) $line->requested_quantity - (int) $line->fulfilled_quantity;
            if ($remaining < 1) {
                return $line->refresh();
            }
            $quantity = min($quantity, $remaining);
            OrderLineFulfillmentEvent::query()->create([
                'fulfillment_id' => $line->id, 'event_type' => 'delivered', 'quantity' => $quantity,
                'external_reference' => $externalReference, 'evidence' => $evidence,
                'recorded_by_user_id' => $actor->id, 'idempotency_key' => $idempotencyKey, 'occurred_at' => now(),
            ]);
            $total = (int) $line->events()->where('event_type', 'delivered')->sum('quantity');
            $line->forceFill(['fulfilled_quantity' => $total, 'status' => $total >= $line->requested_quantity ? 'fulfilled' : 'partial'])->save();
            $obligation = AccountingObligation::query()->firstOrCreate(['source_type' => 'order_line_fulfillment', 'source_id' => $line->id], [
                'reference' => 'supplier-direct:'.$item->order_id.':'.$item->id,
                'quantity' => $total,
                'unit_cost' => $unitCost,
                'currency' => $currency,
                'status' => 'pending',
                'metadata' => array_merge(['order_id' => $item->order_id, 'order_item_id' => $item->id, 'source' => 'supplier_direct'], $metadata ?? []),
                'created_by_user_id' => $actor->id,
            ]);
            if ($unitCost !== null || $currency !== null || $metadata !== null) {
                $obligation->forceFill(['quantity' => $total, 'unit_cost' => $unitCost, 'currency' => $currency, 'metadata' => array_merge((array) $obligation->metadata, $metadata ?? [])])->save();
            }

            return $line->refresh();
        }, 5);
    }

    private function lineKey(int $itemId): string
    {
        $hex = substr(hash('sha256', 'order-line-fulfillment:'.$itemId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
