<?php

namespace App\Services\Orders;

use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\OrderUpgradePlan;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\OrderPermission;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Employee;
use App\Models\InventoryReservation;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Inventory\InventoryService;
use App\Services\ReferenceSequenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly OrderAuthorization $authorization,
        private readonly OrderResponsibilityScopeService $responsibilities,
        private readonly OrderFulfillmentLocationService $locations,
        private readonly OrderTotalsCalculator $totals,
        private readonly ExternalOrderIdentityService $externalIdentity,
        private readonly ReferenceSequenceService $references,
        private readonly InventoryService $inventory,
        private readonly OrderUpgradePlanningService $upgradePlanning,
        private readonly OrderUpgradeService $upgrades,
        private readonly ActivityLogger $activity,
    ) {}

    public function saveAndReserve(SaveAndReserveOrderData $data, User $actor): Order
    {
        $this->authorization->authorize($actor, OrderPermission::Create);
        $this->authorization->authorize($actor, OrderPermission::Reserve);
        $this->authorization->authorize($actor, OrderPermission::EditSellingPrice);

        if ($existing = Order::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing->load(['items.reservations', 'items.upgradeSelection', 'statusEvents']);
        }

        $validated = $this->validate($data, $actor);
        $calculated = $this->totals->calculate($data->items);
        $upgradePlans = $this->upgradePlanning->plans($data->items);
        $reference = $this->references->nextSalesOrderReference((int) substr($validated['order_date'], 0, 4));
        $reservationReferences = [];
        $movementReferences = [];
        $postingKeys = [];
        $lineKeys = [];

        foreach ($data->items as $index => $item) {
            $lineKeys[$index] = (string) Str::uuid();
            $reservationReferences[$index]['base'] = $this->references->nextInventoryReservationReference();
            $movementReferences[$index]['base'] = $this->references->nextStockMovementReference();
            $postingKeys[$index]['base'] = (string) Str::uuid();
            foreach ($upgradePlans[$index]?->installLines() ?? [] as $line) {
                $lineId = (int) $line['upgrade_recipe_line_id'];
                $reservationReferences[$index]['components'][$lineId] = $this->references->nextInventoryReservationReference();
                $movementReferences[$index]['components'][$lineId] = $this->references->nextStockMovementReference();
                $postingKeys[$index]['components'][$lineId] = (string) Str::uuid();
            }
        }

        try {
            return DB::transaction(function () use ($data, $actor, $validated, $calculated, $upgradePlans, $reference, $reservationReferences, $movementReferences, $postingKeys, $lineKeys): Order {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($validated['warehouse_id']);
                $platform = $validated['marketplace_platform_id'] === null
                    ? null
                    : MarketplacePlatform::query()->lockForUpdate()->findOrFail($validated['marketplace_platform_id']);

                $this->locations->assertSelectable($warehouse, $platform);

                if ($this->responsibilities->requiresScope($actor)) {
                    ResponsibilityAssignment::query()->active()->where('employee_id', $actor->employee->id)->lockForUpdate()->get();
                }

                $products = Product::query()->products()
                    ->with('brandRelation')
                    ->whereKey(collect($data->items)->pluck('productId')->sort()->values())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $inventories = ProductInventory::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->whereIn('product_id', $products->keys())
                    ->orderBy('product_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');

                $requiredByProduct = collect($data->items)->groupBy(fn (OrderItemData $item): int => $item->productId)
                    ->map(fn ($items): int => (int) $items->sum(fn (OrderItemData $item): int => $item->quantity));
                foreach ($requiredByProduct as $productId => $requiredQuantity) {
                    $product = $products->get((int) $productId);
                    $inventory = $inventories->get((int) $productId);

                    if ($product?->status !== ProductStatus::Active || $inventory === null || $inventory->sellableQuantity() < $requiredQuantity) {
                        throw ValidationException::withMessages(['items' => $this->stockError($product, $warehouse, $inventory)]);
                    }
                }
                foreach ($data->items as $item) {
                    if (! $this->responsibilities->canAccessProduct($actor, $item->productId, $platform?->id, $warehouse->id)) {
                        throw ValidationException::withMessages(['items' => 'A Product is outside your active Responsibility Assignments.']);
                    }
                }
                $this->upgrades->assertInstallStock($upgradePlans, $warehouse->id);

                $order = Order::query()->create([
                    'reference' => $reference,
                    'source' => OrderSource::Manual,
                    'web_sales_channel' => $validated['web_sales_channel'],
                    'status' => OrderStatus::Draft,
                    'warehouse_id' => $warehouse->id,
                    'marketplace_platform_id' => $platform?->id,
                    'external_order_number' => $validated['external_order_number'],
                    'external_identity_hash' => $validated['external_identity_hash'],
                    'customer_name' => $validated['customer_name'],
                    'customer_phone' => $validated['customer_phone'],
                    'delivery_type' => $validated['delivery_type'],
                    'courier_name' => $validated['courier_name'],
                    'tracking_number' => $validated['tracking_number'],
                    'order_date' => $validated['order_date'],
                    'subtotal' => $calculated['subtotal'],
                    'discount_total' => $calculated['discount_total'],
                    'vat_total' => $calculated['vat_total'],
                    'grand_total' => $calculated['grand_total'],
                    'handled_by_employee_id' => $validated['handled_by_employee_id'],
                    'notes' => $validated['notes'],
                    'idempotency_key' => $data->idempotencyKey,
                    'created_by_user_id' => $actor->id,
                ]);
                $this->timeline($order, null, OrderStatus::Draft, $actor, null, ['item_count' => count($data->items)]);
                $movementGroup = (string) Str::uuid();
                $totalQuantity = 0;

                foreach ($calculated['lines'] as $index => $line) {
                    $product = $products->get($line['product_id']);
                    $item = $order->items()->create([
                        ...$line,
                        'line_number' => $index + 1,
                        'line_key' => $lineKeys[$index],
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'brand_name' => $product->displayBrandName(),
                    ]);
                    $this->inventory->reserveOrderItem(
                        $item->setRelation('order', $order),
                        $actor,
                        $reservationReferences[$index]['base'],
                        $movementReferences[$index]['base'],
                        $postingKeys[$index]['base'],
                        $movementGroup,
                    );
                    if ($upgradePlans[$index] instanceof OrderUpgradePlan) {
                        $selection = $this->upgradePlanning->lockAndCreateSelection($item, $upgradePlans[$index], $actor);
                        $this->upgrades->reserveComponents(
                            $selection,
                            $upgradePlans[$index],
                            $actor,
                            $reservationReferences[$index]['components'] ?? [],
                            $movementReferences[$index]['components'] ?? [],
                            $postingKeys[$index]['components'] ?? [],
                            $movementGroup,
                        );
                    }
                    $totalQuantity += $item->ordered_quantity;
                }

                $order->forceFill([
                    'status' => OrderStatus::Reserved,
                    'reserved_by_user_id' => $actor->id,
                    'reserved_at' => now(),
                ])->save();
                $this->timeline($order, OrderStatus::Draft, OrderStatus::Reserved, $actor, null, [
                    'item_count' => count($data->items),
                    'total_quantity' => $totalQuantity,
                ]);
                $this->activity->log('order.created', $actor, $order, [
                    'order_reference' => $order->reference,
                    'warehouse_id' => $order->warehouse_id,
                    'platform_id' => $order->marketplace_platform_id,
                    'item_count' => count($data->items),
                    'handled_by_employee_id' => $order->handled_by_employee_id,
                ]);
                $this->activity->log('order.reserved', $actor, $order, [
                    'order_reference' => $order->reference,
                    'item_count' => count($data->items),
                    'reserved_quantity' => $totalQuantity,
                ]);

                return $order->load(['items.reservations', 'items.upgradeSelection', 'statusEvents', 'warehouse', 'platform', 'handledBy']);
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            if ($existing = Order::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
                return $existing->load(['items.reservations', 'items.upgradeSelection', 'statusEvents']);
            }

            if ($validated['external_identity_hash'] !== null) {
                throw ValidationException::withMessages(['external_order_number' => 'This Platform Order Number already exists.']);
            }

            throw $exception;
        }
    }

    public function saveAsShipped(SaveAndReserveOrderData $data, User $actor): Order
    {
        $this->authorization->authorize($actor, OrderPermission::Create);
        $this->authorization->authorize($actor, OrderPermission::Fulfill);
        $this->authorization->authorize($actor, OrderPermission::EditSellingPrice);

        if ($existing = Order::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            if ($existing->status === OrderStatus::Fulfilled) {
                return $existing->load(['items.fulfillmentItem', 'fulfillment.items', 'statusEvents']);
            }

            throw new InvalidOrderTransitionException('This submission key belongs to an Order that was not fulfilled.');
        }

        $validated = $this->validate($data, $actor);
        $calculated = $this->totals->calculate($data->items);
        $upgradePlans = $this->upgradePlanning->plans($data->items);
        $year = (int) substr($validated['order_date'], 0, 4);
        $orderReference = $this->references->nextSalesOrderReference($year);
        $fulfillmentReference = $this->references->nextOrderFulfillmentReference($year);
        $movementReferences = [];
        $postingKeys = [];
        $executionKeys = [];
        $lineKeys = [];

        foreach ($data->items as $index => $item) {
            $lineKeys[$index] = (string) Str::uuid();
            $movementReferences[$index]['base'] = $this->references->nextStockMovementReference();
            $postingKeys[$index]['base'] = (string) Str::uuid();
            if ($upgradePlans[$index] instanceof OrderUpgradePlan) {
                $executionKeys[$index] = (string) Str::uuid();
                foreach ($upgradePlans[$index]->lines as $line) {
                    if (! in_array($line['operation'], ['install', 'remove_and_return', 'remove_as_damaged'], true)) {
                        continue;
                    }
                    $lineId = (int) $line['upgrade_recipe_line_id'];
                    $movementReferences[$index]['upgrade'][$lineId] = $this->references->nextStockMovementReference();
                    $postingKeys[$index]['upgrade'][$lineId] = (string) Str::uuid();
                }
            }
        }

        try {
            return DB::transaction(function () use ($data, $actor, $validated, $calculated, $upgradePlans, $orderReference, $fulfillmentReference, $movementReferences, $postingKeys, $executionKeys, $lineKeys): Order {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($validated['warehouse_id']);
                $platform = $validated['marketplace_platform_id'] === null
                    ? null
                    : MarketplacePlatform::query()->lockForUpdate()->findOrFail($validated['marketplace_platform_id']);

                $this->locations->assertSelectable($warehouse, $platform);

                if ($this->responsibilities->requiresScope($actor)) {
                    ResponsibilityAssignment::query()->active()->where('employee_id', $actor->employee->id)->lockForUpdate()->get();
                }

                $products = Product::query()->products()
                    ->with('brandRelation')
                    ->whereKey(collect($data->items)->pluck('productId')->sort()->values())
                    ->lockForUpdate()->get()->keyBy('id');
                $inventories = ProductInventory::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->whereIn('product_id', $products->keys())
                    ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

                $requiredByProduct = collect($data->items)->groupBy(fn (OrderItemData $item): int => $item->productId)
                    ->map(fn ($items): int => (int) $items->sum(fn (OrderItemData $item): int => $item->quantity));
                foreach ($requiredByProduct as $productId => $requiredQuantity) {
                    $product = $products->get((int) $productId);
                    $inventory = $inventories->get((int) $productId);

                    if ($product?->status !== ProductStatus::Active || $inventory === null || $inventory->sellableQuantity() < $requiredQuantity) {
                        throw ValidationException::withMessages(['items' => $this->stockError($product, $warehouse, $inventory)]);
                    }
                    if ($inventory->average_cost === null) {
                        throw ValidationException::withMessages(['items' => "{$product->sku} has no Inventory Average Cost and cannot be shipped."]);
                    }
                }
                foreach ($data->items as $item) {
                    if (! $this->responsibilities->canAccessProduct($actor, $item->productId, $platform?->id, $warehouse->id)) {
                        throw ValidationException::withMessages(['items' => 'A Product is outside your active Responsibility Assignments.']);
                    }
                }
                $this->upgrades->assertInstallStock($upgradePlans, $warehouse->id);

                $order = Order::query()->create([
                    'reference' => $orderReference,
                    'source' => OrderSource::Manual,
                    'web_sales_channel' => $validated['web_sales_channel'],
                    'status' => OrderStatus::Draft,
                    'warehouse_id' => $warehouse->id,
                    'marketplace_platform_id' => $platform?->id,
                    'external_order_number' => $validated['external_order_number'],
                    'external_identity_hash' => $validated['external_identity_hash'],
                    'customer_name' => $validated['customer_name'],
                    'customer_phone' => $validated['customer_phone'],
                    'delivery_type' => $validated['delivery_type'],
                    'courier_name' => $validated['courier_name'],
                    'tracking_number' => $validated['tracking_number'],
                    'order_date' => $validated['order_date'],
                    'subtotal' => $calculated['subtotal'],
                    'discount_total' => $calculated['discount_total'],
                    'vat_total' => $calculated['vat_total'],
                    'grand_total' => $calculated['grand_total'],
                    'handled_by_employee_id' => $validated['handled_by_employee_id'],
                    'notes' => $validated['notes'],
                    'idempotency_key' => $data->idempotencyKey,
                    'created_by_user_id' => $actor->id,
                ]);
                $this->timeline($order, null, OrderStatus::Draft, $actor, null, ['item_count' => count($data->items)]);

                foreach ($calculated['lines'] as $index => $line) {
                    $product = $products->get($line['product_id']);
                    $item = $order->items()->create([
                        ...$line,
                        'line_number' => $index + 1,
                        'line_key' => $lineKeys[$index],
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'brand_name' => $product->displayBrandName(),
                    ]);
                    if ($upgradePlans[$index] instanceof OrderUpgradePlan) {
                        $this->upgradePlanning->lockAndCreateSelection($item, $upgradePlans[$index], $actor);
                    }
                }

                $fulfillment = OrderFulfillment::query()->create([
                    'reference' => $fulfillmentReference,
                    'order_id' => $order->id,
                    'movement_group' => (string) Str::uuid(),
                    'idempotency_key' => $data->idempotencyKey,
                    'fulfilled_by_user_id' => $actor->id,
                    'fulfilled_at' => now(),
                ]);
                $order->setRelation('fulfillment', $fulfillment);
                $this->upgrades->lockFulfillmentInventories($order->load('items.upgradeSelection'));
                $quantity = 0;

                foreach ($order->items()->with('upgradeSelection')->orderBy('line_number')->get() as $item) {
                    $index = $item->line_number - 1;
                    $fulfillmentItem = $this->inventory->fulfillOrderItem(
                        $item->setRelation('order', $order),
                        $fulfillment,
                        $actor,
                        $movementReferences[$index]['base'],
                        $postingKeys[$index]['base'],
                    );
                    if ($item->upgradeSelection !== null) {
                        $this->upgrades->execute(
                            $item->upgradeSelection,
                            $fulfillmentItem,
                            $actor,
                            $executionKeys[$index],
                            $movementReferences[$index]['upgrade'] ?? [],
                            $postingKeys[$index]['upgrade'] ?? [],
                            true,
                        );
                    }
                    $quantity += $item->ordered_quantity;
                }

                $order->forceFill(['status' => OrderStatus::Fulfilled])->save();
                $this->timeline($order, OrderStatus::Draft, OrderStatus::Fulfilled, $actor, null, [
                    'item_count' => count($data->items),
                    'fulfilled_quantity' => $quantity,
                    'direct' => true,
                ]);
                $this->activity->log('order.created', $actor, $order, [
                    'order_reference' => $order->reference,
                    'warehouse_id' => $order->warehouse_id,
                    'platform_id' => $order->marketplace_platform_id,
                    'item_count' => count($data->items),
                    'handled_by_employee_id' => $order->handled_by_employee_id,
                ]);
                $this->activity->log('order.fulfilled', $actor, $order, [
                    'order_reference' => $order->reference,
                    'fulfillment_reference' => $fulfillment->reference,
                    'item_count' => count($data->items),
                    'fulfilled_quantity' => $quantity,
                    'direct' => true,
                ]);

                return $order->load(['items.upgradeSelection.execution', 'items.fulfillmentItem.upgradeExecution', 'fulfillment.items', 'statusEvents', 'warehouse', 'platform', 'handledBy']);
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            if ($existing = Order::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
                return $existing->load(['items.fulfillmentItem', 'fulfillment.items', 'statusEvents']);
            }

            if ($validated['external_identity_hash'] !== null) {
                throw ValidationException::withMessages(['external_order_number' => 'This Platform Order Number already exists.']);
            }

            throw $exception;
        }
    }

    public function fulfill(Order $order, string $idempotencyKey, User $actor): Order
    {
        $this->authorization->authorize($actor, OrderPermission::Fulfill, $order);

        if ($existing = OrderFulfillment::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->order->load(['items.reservations', 'items.upgradeSelection.execution', 'items.fulfillmentItem.upgradeExecution', 'fulfillment.items', 'statusEvents']);
        }

        Validator::make(['idempotency_key' => $idempotencyKey], ['idempotency_key' => ['required', 'uuid']])->validate();
        $items = $order->items()->with('upgradeSelection')->orderBy('line_number')->get();
        $year = (int) $order->order_date->format('Y');
        $fulfillmentReference = $this->references->nextOrderFulfillmentReference($year);
        $movementReferences = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => $this->references->nextStockMovementReference()]);
        $postingKeys = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => (string) Str::uuid()]);
        $upgradeMovementReferences = [];
        $upgradePostingKeys = [];
        $upgradeExecutionKeys = [];
        foreach ($items as $item) {
            if ($item->upgradeSelection === null) {
                continue;
            }
            $upgradeExecutionKeys[$item->id] = (string) Str::uuid();
            foreach ($item->upgradeSelection->recipe_snapshot['lines'] ?? [] as $line) {
                if (! in_array($line['operation'], ['install', 'remove_and_return', 'remove_as_damaged'], true)) {
                    continue;
                }
                $lineId = (int) $line['upgrade_recipe_line_id'];
                $upgradeMovementReferences[$item->id][$lineId] = $this->references->nextStockMovementReference();
                $upgradePostingKeys[$item->id][$lineId] = (string) Str::uuid();
            }
        }

        return DB::transaction(function () use ($order, $idempotencyKey, $actor, $fulfillmentReference, $movementReferences, $postingKeys, $upgradeMovementReferences, $upgradePostingKeys, $upgradeExecutionKeys): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($existing = OrderFulfillment::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing->order->load(['items.reservations', 'items.upgradeSelection.execution', 'items.fulfillmentItem.upgradeExecution', 'fulfillment.items', 'statusEvents']);
            }

            $this->authorization->authorize($actor, OrderPermission::Fulfill, $order);

            if ($order->status !== OrderStatus::Reserved) {
                throw new InvalidOrderTransitionException('Only a fully Reserved Order can be shipped.');
            }

            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($order->warehouse_id);
            $platform = $order->marketplace_platform_id === null ? null : MarketplacePlatform::query()->lockForUpdate()->findOrFail($order->marketplace_platform_id);

            $this->locations->assertSelectable($warehouse, $platform);
            if ($this->responsibilities->requiresScope($actor)) {
                ResponsibilityAssignment::query()->active()->where('employee_id', $actor->employee->id)->lockForUpdate()->get();
            }

            $items = $order->items()->with(['reservation', 'componentReservations', 'upgradeSelection'])->orderBy('line_number')->lockForUpdate()->get();

            if ($items->isEmpty() || $items->contains(fn (OrderItem $item): bool => $item->reservation === null)) {
                throw new InvalidOrderTransitionException('Every Order Item must have its linked active reservation before shipping.');
            }

            foreach ($items as $item) {
                if (! $this->responsibilities->canAccessProduct($actor, $item->product_id, $platform?->id, $warehouse->id)) {
                    throw ValidationException::withMessages(['items' => 'A Product is outside your active Responsibility Assignments.']);
                }
            }

            $this->upgrades->lockFulfillmentInventories($order->setRelation('items', $items));

            $fulfillment = OrderFulfillment::query()->create([
                'reference' => $fulfillmentReference,
                'order_id' => $order->id,
                'movement_group' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'fulfilled_by_user_id' => $actor->id,
                'fulfilled_at' => now(),
            ]);
            $quantity = 0;

            foreach ($items as $item) {
                $fulfillmentItem = $this->inventory->fulfillOrderItem(
                    $item->setRelation('order', $order),
                    $fulfillment,
                    $actor,
                    $movementReferences[$item->id],
                    $postingKeys[$item->id],
                    $item->reservation,
                );
                if ($item->upgradeSelection !== null) {
                    $this->upgrades->execute(
                        $item->upgradeSelection,
                        $fulfillmentItem,
                        $actor,
                        $upgradeExecutionKeys[$item->id],
                        $upgradeMovementReferences[$item->id] ?? [],
                        $upgradePostingKeys[$item->id] ?? [],
                        false,
                    );
                }
                $quantity += $item->ordered_quantity;
            }

            $order->forceFill(['status' => OrderStatus::Fulfilled])->save();
            $this->timeline($order, OrderStatus::Reserved, OrderStatus::Fulfilled, $actor, null, [
                'item_count' => $items->count(),
                'fulfilled_quantity' => $quantity,
                'direct' => false,
            ]);
            $this->activity->log('order.fulfilled', $actor, $order, [
                'order_reference' => $order->reference,
                'fulfillment_reference' => $fulfillment->reference,
                'item_count' => $items->count(),
                'fulfilled_quantity' => $quantity,
                'used_reservations' => true,
            ]);

            return $order->load(['items.reservations', 'items.upgradeSelection.execution', 'items.fulfillmentItem.upgradeExecution', 'fulfillment.items', 'statusEvents']);
        }, 5);
    }

    public function cancel(Order $order, CancelOrderData $data, User $actor): Order
    {
        if ($order->status === OrderStatus::Cancelled && $order->cancellation_idempotency_key === $data->idempotencyKey) {
            return $order->load(['items.reservations', 'items.upgradeSelection', 'statusEvents']);
        }

        $this->authorization->authorize($actor, OrderPermission::Cancel, $order);
        $reason = trim($data->reason);

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A cancellation reason is required and may not exceed 2000 characters.']);
        }

        $reservations = $order->items()->with('reservations')->get()->flatMap->reservations
            ->filter(fn ($reservation) => $reservation?->status?->value === 'active');
        $movementReferences = $reservations->mapWithKeys(fn (InventoryReservation $reservation): array => [$reservation->id => $this->references->nextStockMovementReference()]);
        $releaseKeys = $reservations->mapWithKeys(fn (InventoryReservation $reservation): array => [$reservation->id => (string) Str::uuid()]);

        return DB::transaction(function () use ($order, $data, $actor, $reason, $movementReferences, $releaseKeys): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === OrderStatus::Cancelled && $order->cancellation_idempotency_key === $data->idempotencyKey) {
                return $order->load(['items.reservations', 'items.upgradeSelection', 'statusEvents']);
            }

            $this->authorization->authorize($actor, OrderPermission::Cancel, $order);

            if (! in_array($order->status, [OrderStatus::Draft, OrderStatus::Reserved], true)) {
                throw new InvalidOrderTransitionException('Only Draft or Reserved Orders can be cancelled before fulfilment.');
            }

            $from = $order->status;
            $movementGroup = (string) Str::uuid();
            $reservations = $order->items()->with('reservations')->get()->flatMap->reservations;

            $releasedCount = 0;

            foreach ($reservations as $reservation) {
                if ($reservation->status?->value !== 'active') {
                    continue;
                }

                $this->inventory->releaseOrderReservation(
                    $reservation,
                    $actor,
                    $movementReferences[$reservation->id],
                    $releaseKeys[$reservation->id],
                    $movementGroup,
                    $reason,
                );
                $releasedCount++;
            }

            $order->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_by_user_id' => $actor->id,
                'cancellation_idempotency_key' => $data->idempotencyKey,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $this->timeline($order, $from, OrderStatus::Cancelled, $actor, $reason);
            $this->activity->log('order.cancelled', $actor, $order, [
                'order_reference' => $order->reference,
                'released_reservation_count' => $releasedCount,
                'reason_recorded' => true,
            ]);

            return $order->load(['items.reservations', 'items.upgradeSelection', 'statusEvents']);
        }, 5);
    }

    public function reserveDraft(Order $order, User $actor): Order
    {
        $this->authorization->authorize($actor, OrderPermission::Reserve, $order);
        $items = $order->items()->with('upgradeSelection')->orderBy('line_number')->get();
        $reservationReferences = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => $this->references->nextInventoryReservationReference()]);
        $movementReferences = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => $this->references->nextStockMovementReference()]);
        $postingKeys = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => (string) Str::uuid()]);
        $upgradePlans = [];
        $componentReservationReferences = [];
        $componentMovementReferences = [];
        $componentPostingKeys = [];
        foreach ($items as $item) {
            if ($item->upgradeSelection === null) {
                continue;
            }
            $plan = $this->upgradePlanning->planFromSelection($item->upgradeSelection);
            $upgradePlans[$item->id] = $plan;
            foreach ($plan->installLines() as $line) {
                $lineId = (int) $line['upgrade_recipe_line_id'];
                $componentReservationReferences[$item->id][$lineId] = $this->references->nextInventoryReservationReference();
                $componentMovementReferences[$item->id][$lineId] = $this->references->nextStockMovementReference();
                $componentPostingKeys[$item->id][$lineId] = (string) Str::uuid();
            }
        }

        return DB::transaction(function () use ($order, $actor, $reservationReferences, $movementReferences, $postingKeys, $upgradePlans, $componentReservationReferences, $componentMovementReferences, $componentPostingKeys): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorization->authorize($actor, OrderPermission::Reserve, $order);

            if ($order->status !== OrderStatus::Draft) {
                throw new InvalidOrderTransitionException('Only a Draft Order can be reserved.');
            }

            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($order->warehouse_id);
            $platform = $order->marketplace_platform_id === null ? null : MarketplacePlatform::query()->lockForUpdate()->findOrFail($order->marketplace_platform_id);

            $this->locations->assertSelectable($warehouse, $platform);

            if ($this->responsibilities->requiresScope($actor)) {
                ResponsibilityAssignment::query()->active()->where('employee_id', $actor->employee->id)->lockForUpdate()->get();
            }

            $items = $order->items()->with('upgradeSelection')->orderBy('line_number')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Add at least one Product before reserving.']);
            }

            $inventories = ProductInventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $items->pluck('product_id'))
                ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

            $requiredByProduct = $items->groupBy('product_id')->map(fn ($group): int => (int) $group->sum('ordered_quantity'));
            foreach ($requiredByProduct as $productId => $requiredQuantity) {
                $product = Product::query()->products()->lockForUpdate()->findOrFail((int) $productId);
                $inventory = $inventories->get((int) $productId);

                if ($product->status !== ProductStatus::Active || $inventory === null || $inventory->sellableQuantity() < $requiredQuantity) {
                    throw ValidationException::withMessages(['items' => $this->stockError($product, $warehouse, $inventory)]);
                }
            }
            foreach ($items as $item) {
                $product = Product::query()->products()->lockForUpdate()->findOrFail($item->product_id);
                if (! $this->responsibilities->canAccessProduct($actor, $item->product_id, $platform?->id, $warehouse->id)) {
                    throw ValidationException::withMessages(['items' => 'A Product is outside your active Responsibility Assignments.']);
                }
                if ($item->upgradeSelection !== null) {
                    $this->upgradePlanning->assertSelectionCurrent($item->upgradeSelection);
                }
            }
            $this->upgrades->assertInstallStock(array_values($upgradePlans), $warehouse->id);

            $movementGroup = (string) Str::uuid();
            $quantity = 0;

            foreach ($items as $item) {
                $this->inventory->reserveOrderItem(
                    $item->setRelation('order', $order),
                    $actor,
                    $reservationReferences[$item->id],
                    $movementReferences[$item->id],
                    $postingKeys[$item->id],
                    $movementGroup,
                );
                if ($item->upgradeSelection !== null) {
                    $this->upgrades->reserveComponents(
                        $item->upgradeSelection,
                        $upgradePlans[$item->id],
                        $actor,
                        $componentReservationReferences[$item->id] ?? [],
                        $componentMovementReferences[$item->id] ?? [],
                        $componentPostingKeys[$item->id] ?? [],
                        $movementGroup,
                    );
                }
                $quantity += $item->ordered_quantity;
            }

            $order->forceFill(['status' => OrderStatus::Reserved, 'reserved_by_user_id' => $actor->id, 'reserved_at' => now()])->save();
            $this->timeline($order, OrderStatus::Draft, OrderStatus::Reserved, $actor, null, ['item_count' => $items->count(), 'total_quantity' => $quantity]);
            $this->activity->log('order.reserved', $actor, $order, [
                'order_reference' => $order->reference,
                'item_count' => $items->count(),
                'reserved_quantity' => $quantity,
            ]);

            return $order->load(['items.reservations', 'items.upgradeSelection', 'statusEvents']);
        }, 5);
    }

    /** @return array<string, mixed> */
    private function validate(SaveAndReserveOrderData $data, User $actor): array
    {
        $items = array_map(fn (OrderItemData $item): array => [
            'product_id' => $item->productId,
            'quantity' => $item->quantity,
            'selling_price' => trim($item->sellingPrice),
            'discount_total' => trim($item->discountTotal),
            'vat_rate' => trim($item->vatRate),
            'notes' => $item->notes,
            'sales_configuration_id' => $item->salesConfigurationId,
            'upgrade_recipe_id' => $item->upgradeRecipeId,
        ], $data->items);
        $externalNumber = $data->externalOrderNumber === null ? null : trim($data->externalOrderNumber);
        $externalHash = $this->externalIdentity->hash($data->platformId, $externalNumber);
        $handledBy = $data->handledByEmployeeId ?? $actor->employee?->id;

        $validated = Validator::make([
            'warehouse_id' => $data->warehouseId,
            'marketplace_platform_id' => $data->platformId,
            'external_order_number' => $externalNumber === '' ? null : $externalNumber,
            'external_identity_hash' => $externalHash,
            'web_sales_channel' => $data->webSalesChannel,
            'customer_name' => $data->customerName === null ? null : trim($data->customerName),
            'customer_phone' => $data->customerPhone === null ? null : trim($data->customerPhone),
            'delivery_type' => $data->deliveryType,
            'courier_name' => $data->courierName === null ? null : trim($data->courierName),
            'tracking_number' => $data->trackingNumber === null ? null : trim($data->trackingNumber),
            'order_date' => $data->orderDate,
            'handled_by_employee_id' => $handledBy,
            'notes' => $data->notes === null ? null : trim($data->notes),
            'idempotency_key' => $data->idempotencyKey,
            'items' => $items,
        ], [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'marketplace_platform_id' => ['nullable', 'integer', 'exists:marketplace_platforms,id'],
            'external_order_number' => ['nullable', 'string', 'max:255'],
            'external_identity_hash' => ['nullable', 'string', 'size:64', 'unique:orders,external_identity_hash'],
            'web_sales_channel' => ['nullable', 'in:website,whatsapp,walk_in,other'],
            'customer_name' => ['nullable', 'required_with:web_sales_channel', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'required_with:web_sales_channel', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{5,39}$/'],
            'delivery_type' => ['nullable', 'required_with:web_sales_channel', 'in:courier,shop_pickup'],
            'courier_name' => ['nullable', 'required_if:delivery_type,courier', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'order_date' => ['required', 'date'],
            'handled_by_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.selling_price' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'items.*.discount_total' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'items.*.vat_rate' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.sales_configuration_id' => ['nullable', 'integer', 'required_with:items.*.upgrade_recipe_id', 'exists:sales_configurations,id'],
            'items.*.upgrade_recipe_id' => ['nullable', 'integer', 'required_with:items.*.sales_configuration_id', 'exists:upgrade_recipes,id'],
        ])->after(function ($validator) use ($data, $actor, $handledBy): void {
            $warehouse = Warehouse::query()->find($data->warehouseId);
            $platform = $data->platformId === null ? null : MarketplacePlatform::query()->find($data->platformId);
            $employee = Employee::query()->find($handledBy);

            if ($warehouse === null || ! $this->locations->isSelectable($warehouse, $platform)) {
                $validator->errors()->add('warehouse_id', 'Select an active Fulfilled From location available for this Platform.');
            }
            if ($platform !== null && $platform->status !== true) {
                $validator->errors()->add('marketplace_platform_id', 'Select an active Platform.');
            }
            if ($employee?->status !== true) {
                $validator->errors()->add('handled_by_employee_id', 'Handled By must be an active Employee.');
            }
            foreach ($data->items as $index => $item) {
                if (! $this->responsibilities->canAccessProduct($actor, $item->productId, $data->platformId, $data->warehouseId)) {
                    $validator->errors()->add("items.{$index}.product_id", 'This Product is outside your active Responsibility Assignments.');
                }
            }
        })->validate();

        return $validated;
    }

    private function stockError(?Product $product, Warehouse $location, ?ProductInventory $inventory): string
    {
        $sellable = $inventory?->sellableQuantity() ?? 0;
        $productLabel = $product?->sku ?? 'This Product';

        return "Only {$sellable} sellable units of {$productLabel} are available at {$location->name}.";
    }

    /** @param array<string, mixed> $context */
    private function timeline(Order $order, ?OrderStatus $from, OrderStatus $to, User $actor, ?string $reason = null, array $context = []): void
    {
        OrderStatusEvent::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->id,
            'reason' => $reason,
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }
}
