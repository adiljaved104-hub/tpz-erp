<?php

namespace App\Services\Orders;

use App\Models\OrderLineFulfillment;
use App\Models\OrderLineFulfillmentEvent;
use App\Models\OrderItem;
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
            $line = OrderLineFulfillment::query()->firstOrCreate(['idempotency_key' => 'line-'.$item->id], [
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
            return $line->refresh();
        }, 5);
    }
}
