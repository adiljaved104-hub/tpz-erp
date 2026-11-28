<?php

namespace App\Services\Orders;

use App\Enums\InventoryPermission;
use App\Enums\InventoryReservationStatus;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderStatusEvent;
use App\Models\StockTransferReservation;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Inventory\InventoryService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Releases a complete order bundle, never an allocation or transfer hold. */
class OrderReservationReleaseService
{
    public function reservations(Order $order)
    {
        return InventoryReservation::query()->whereHas('orderItem', fn ($q) => $q->where('order_id', $order->id));
    }

    public function allows(User $actor, Order $order): bool
    {
        return $order->status === OrderStatus::Reserved
            && $order->web_sales_channel === null
            && app(OrderAuthorization::class)->allows($actor, OrderPermission::View, $order)
            && app(OrderAuthorization::class)->allows($actor, OrderPermission::Reserve, $order)
            && ! $this->reservations($order)->where('status', InventoryReservationStatus::Fulfilled)->exists()
            && ! OrderFulfillment::query()->where('order_id', $order->id)->exists()
            && $this->reservations($order)->where('status', InventoryReservationStatus::Active)->exists()
            && $this->reservations($order)->where('status', InventoryReservationStatus::Active)->with('inventory')->get()
                ->every(fn ($r) => app(InventoryAuthorization::class)->allows($actor, InventoryPermission::ReleaseReservation, $r->inventory));
    }

    public function release(Order $order, User $actor, string $reason, string $operationKey): Order
    {
        Validator::make(['reason' => trim($reason), 'operation_key' => $operationKey], [
            'reason' => ['required', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid'],
        ])->validate();
        app(OrderAuthorization::class)->authorize($actor, OrderPermission::Reserve, $order);
        $receipt = fn () => ActivityLog::query()->where('event', 'order.reservations_released')
            ->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)
            ->where('actor_user_id', $actor->id)->where('properties->operation_key', $operationKey)->exists();
        if ($receipt()) {
            return $order->fresh();
        }
        abort_unless($this->allows($actor, $order), 403);
        // Reference allocation must remain outside every business transaction.
        $references = $this->reservations($order)->where('status', InventoryReservationStatus::Active)
            ->orderBy('id')->pluck('id')->mapWithKeys(fn ($id) => [$id => app(ReferenceSequenceService::class)->nextStockMovementReference()])->all();

        return DB::transaction(function () use ($order, $actor, $reason, $operationKey, $receipt, $references): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            app(OrderAuthorization::class)->authorize($actor, OrderPermission::Reserve, $locked);
            if ($receipt()) {
                return $locked;
            }
            abort_unless($this->allows($actor, $locked), 403);
            $reservations = $this->reservations($locked)->where('status', InventoryReservationStatus::Active)->orderBy('product_inventory_id')->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                if (! isset($references[$reservation->id]) || StockTransferReservation::query()->where('inventory_reservation_id', $reservation->id)->exists()) {
                    throw ValidationException::withMessages(['release' => 'Reservations changed or include a transfer hold. Refresh and review again.']);
                }
                app(InventoryAuthorization::class)->authorize($actor, InventoryPermission::ReleaseReservation, $reservation->inventory);
                app(InventoryService::class)->releaseOrderReservation($reservation, $actor, $references[$reservation->id], (string) Str::uuid(), $operationKey, trim($reason));
            }
            $locked->forceFill(['status' => OrderStatus::Draft, 'reserved_by_user_id' => null, 'reserved_at' => null])->save();
            OrderStatusEvent::query()->create(['order_id' => $locked->id, 'from_status' => OrderStatus::Reserved, 'to_status' => OrderStatus::Draft, 'actor_user_id' => $actor->id, 'reason' => trim($reason), 'created_at' => now()]);
            app(ActivityLogger::class)->log('order.reservations_released', $actor, $locked, ['operation_key' => $operationKey, 'reservation_ids' => $reservations->modelKeys(), 'reason' => trim($reason)]);

            return $locked;
        }, 5);
    }
}
