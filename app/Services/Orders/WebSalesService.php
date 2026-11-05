<?php

namespace App\Services\Orders;

use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\OrderStatus;
use App\Enums\WebSalesDeliveryType;
use App\Enums\WebSalesPermission;
use App\Exceptions\DefaultWarehouseException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\DefaultWarehouseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WebSalesService
{
    public function __construct(
        private readonly WebSalesAuthorization $authorization,
        private readonly DefaultWarehouseService $warehouses,
        private readonly OrderService $orders,
        private readonly ActivityLogger $activity,
        private readonly ManualSourcingService $manualSourcing,
        private readonly ManualSourcingShipmentService $manualShipment,
    ) {}

    public function createConfirmed(WebSalesOrderData $data, User $actor): Order
    {
        $this->authorization->authorize($actor, WebSalesPermission::Create);
        if ($existing = Order::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            abort_unless($existing->created_by_user_id === $actor->id, 403);

            return $existing->load(['items', 'manualSourcingLines']);
        }
        $data = $this->materializeManualLines($data, $actor);
        $isManual = collect($data->items)->contains(fn ($item): bool => $item->sourceType === 'manual_sourced');
        $order = $isManual ? $this->orders->saveDraft($this->orderData($data, $actor), $actor) : $this->orders->saveAndReserve($this->orderData($data, $actor), $actor);
        if ($isManual) {
            $order->forceFill(['status' => OrderStatus::PendingReview])->save();
            $this->manualSourcing->recordLines($order, $data->items, $actor);
        }
        $this->logCreated($order, $actor, false);

        return $order;
    }

    public function completeSale(WebSalesOrderData $data, User $actor): Order
    {
        $this->authorization->authorize($actor, WebSalesPermission::Create);
        $validated = Validator::make([
            'channel' => $data->channel->value,
            'delivery_type' => $data->deliveryType->value,
        ], [
            'channel' => ['in:walk_in'],
            'delivery_type' => ['in:shop_pickup'],
        ], [
            'channel.in' => 'Complete Sale is available only for Walk-in orders.',
            'delivery_type.in' => 'Complete Sale requires Shop Pickup.',
        ])->validate();
        unset($validated);

        if (collect($data->items)->contains(fn ($item): bool => $item->sourceType === 'manual_sourced')) {
            throw ValidationException::withMessages(['items' => 'Manual sourced lines require verified receipt before fulfilment.']);
        }

        $order = $this->orders->saveAsShipped($this->orderData($data, $actor), $actor);
        $this->logCreated($order, $actor, true);

        return $order;
    }

    public function ship(Order $order, string $idempotencyKey, User $actor): Order
    {
        $this->authorization->authorize($actor, WebSalesPermission::Update, $order);

        return DB::transaction(function () use ($order, $idempotencyKey, $actor): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorization->authorize($actor, WebSalesPermission::Update, $order);
            if ($order->status === OrderStatus::Fulfilled) {
                // Delegate replay-key ownership checks to the existing fulfillment ledger.
                // Never materialize or revalidate already fulfilled manual sourcing lines.
                return $this->orders->fulfill($order, $idempotencyKey, $actor);
            }
            if ($order->manualSourcingLines()->exists()) {
                $this->manualShipment->materialize($order, $idempotencyKey, $actor);
                $order->refresh();
                $this->manualSourcing->assertReadyForFulfilment($order);
                if ($order->status === OrderStatus::PendingReview) {
                    $order = $this->orders->reserveDraft($order, $actor);
                }
            }
            $order = $this->orders->fulfill($order, $idempotencyKey, $actor);
            if ($order->manualSourcingLines()->exists()) {
                $this->manualSourcing->markFulfilled($order, $actor);
            }
            $this->activity->log('web_sales.order_shipped', $actor, $order, ['order_reference' => $order->reference]);

            return $order;
        }, 5);
    }

    public function deliver(Order $order, User $actor): Order
    {
        $this->authorization->authorize($actor, WebSalesPermission::Update, $order);

        return DB::transaction(function () use ($order, $actor): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorization->authorize($actor, WebSalesPermission::Update, $order);

            if ($order->delivered_at !== null) {
                return $order;
            }
            if ($order->status !== OrderStatus::Fulfilled) {
                throw new InvalidOrderTransitionException('Only a Shipped Web Sale can be marked Delivered.');
            }

            $order->forceFill(['delivered_at' => now()])->save();
            $this->activity->log('web_sales.order_delivered', $actor, $order, ['order_reference' => $order->reference]);

            return $order->refresh();
        });
    }

    public function cancel(Order $order, string $reason, string $idempotencyKey, User $actor): Order
    {
        $this->authorization->authorize($actor, WebSalesPermission::Cancel, $order);

        return $this->orders->cancel($order, new CancelOrderData($reason, $idempotencyKey), $actor);
    }

    /**
     * Edit Web Sale customer snapshots before dispatch, without rewriting
     * reservations, sourcing purchases, invoices or fulfillment history.
     *
     * @param array{customer_name:string,customer_phone:string,customer_address?:?string,notes?:?string} $data
     */
    public function updateDetails(Order $order, array $data, User $actor): Order
    {
        $this->authorization->authorize($actor, WebSalesPermission::Update, $order);

        $validated = Validator::make($data, [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($order, $validated, $actor): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorization->authorize($actor, WebSalesPermission::Update, $order);

            if (! in_array($order->status, [
                OrderStatus::Draft, OrderStatus::Reserved, OrderStatus::PendingReview, OrderStatus::Confirmed,
            ], true) || $order->fulfillment()->exists() || $order->taxInvoices()->exists()) {
                throw ValidationException::withMessages([
                    'order' => 'Web Sale details are locked after shipment, cancellation or Tax Invoice generation.',
                ]);
            }

            $next = [
                'customer_name' => trim($validated['customer_name']),
                'customer_phone' => $this->normalizePhone($validated['customer_phone']),
                'customer_address' => filled($validated['customer_address'] ?? null)
                    ? trim((string) $validated['customer_address']) : null,
                'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            ];
            if ($next['customer_name'] === '' || mb_strlen(preg_replace('/\\D+/', '', $next['customer_phone'])) < 7) {
                throw ValidationException::withMessages(['customer_phone' => 'Enter a valid customer name and phone number.']);
            }

            $before = $order->only(array_keys($next));
            if ($before === $next) {
                return $order;
            }

            $order->forceFill($next)->save();
            $this->activity->log('web_sales.details_updated', $actor, $order, [
                'order_reference' => $order->reference,
                'fields_changed' => array_keys(array_diff_assoc($next, $before)),
                'before' => $before,
                'after' => $next,
            ]);

            return $order->refresh();
        }, 5);
    }

    /** @param array{delivery_type: string, courier_name?: string|null, tracking_number?: string|null} $data */
    public function updateDelivery(Order $order, array $data, User $actor): Order
    {
        $this->authorization->authorize($actor, WebSalesPermission::Update, $order);
        if (($data['delivery_type'] ?? null) instanceof WebSalesDeliveryType) {
            $data['delivery_type'] = $data['delivery_type']->value;
        }
        $validated = Validator::make($data, [
            'delivery_type' => ['required', 'in:courier,shop_pickup'],
            'courier_name' => ['nullable', 'required_if:delivery_type,courier', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return DB::transaction(function () use ($order, $validated, $actor): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorization->authorize($actor, WebSalesPermission::Update, $order);
            $old = $order->only(['delivery_type', 'courier_name', 'tracking_number']);
            $pickup = $validated['delivery_type'] === WebSalesDeliveryType::ShopPickup->value;
            $order->forceFill([
                'delivery_type' => $validated['delivery_type'],
                'courier_name' => $pickup ? null : trim((string) $validated['courier_name']),
                'tracking_number' => $pickup ? null : (filled($validated['tracking_number'] ?? null) ? trim((string) $validated['tracking_number']) : null),
            ])->save();
            $this->activity->log('web_sales.delivery_updated', $actor, $order, [
                'order_reference' => $order->reference,
                'old' => $old,
                'new' => $order->only(['delivery_type', 'courier_name', 'tracking_number']),
            ]);

            return $order->refresh();
        });
    }

    private function orderData(WebSalesOrderData $data, User $actor): SaveAndReserveOrderData
    {
        $warehouse = $this->warehouses->operationalDefault();
        if ($warehouse->code !== 'MAIN') {
            throw new DefaultWarehouseException('Main Warehouse is unavailable. Web Sales cannot continue until the active default is Main Warehouse.');
        }

        return new SaveAndReserveOrderData(
            warehouseId: (int) $warehouse->id,
            platformId: null,
            externalOrderNumber: null,
            orderDate: now(config('app.timezone'))->toDateString(),
            handledByEmployeeId: $actor->employee?->id,
            notes: $data->notes,
            items: $data->items,
            idempotencyKey: $data->idempotencyKey,
            webSalesChannel: $data->channel->value,
            customerName: trim($data->customerName),
            customerPhone: $this->normalizePhone($data->customerPhone),
            customerAddress: filled($data->customerAddress) ? trim((string) $data->customerAddress) : null,
            deliveryType: $data->deliveryType->value,
            courierName: $data->deliveryType === WebSalesDeliveryType::ShopPickup ? null : $data->courierName,
            trackingNumber: $data->deliveryType === WebSalesDeliveryType::ShopPickup ? null : $data->trackingNumber,
        );
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        $prefix = str_starts_with($phone, '+') ? '+' : '';

        return $prefix.preg_replace('/\D+/', '', $phone);
    }

    private function materializeManualLines(WebSalesOrderData $data, User $actor): WebSalesOrderData
    {
        return new WebSalesOrderData($data->customerName, $data->customerPhone, $data->channel, $data->deliveryType, $data->courierName, $data->trackingNumber, $this->manualSourcing->materializeItems($data->items, $actor), $data->idempotencyKey, $data->notes, $data->customerAddress);
    }

    private function logCreated(Order $order, User $actor, bool $completed): void
    {
        $this->activity->log('web_sales.order_created', $actor, $order, [
            'order_reference' => $order->reference,
            'channel' => $order->web_sales_channel?->value,
            'delivery_type' => $order->delivery_type?->value,
            'item_count' => $order->items->count(),
            'completed_immediately' => $completed,
        ]);
    }
}
