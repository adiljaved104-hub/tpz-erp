<?php

namespace App\Services\Returns;

use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnInspectionResult;
use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnReason;
use App\Enums\CustomerReturnSource;
use App\Enums\CustomerReturnStatus;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Enums\OrderStatus;
use App\Enums\StockMovementBucket;
use App\Enums\StockMovementType;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnInspection;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnStatusEvent;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\StockMovementBucketChange;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Claims\SafetClaimService;
use App\Services\Inventory\DamagedStockEventService;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerReturnService
{
    public function __construct(
        private readonly CustomerReturnAuthorization $authorization,
        private readonly InventoryBalanceService $balances,
        private readonly WeightedAverageCostCalculator $costs,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
        private readonly DamagedStockEventService $damagedStock,
        private readonly SafetClaimService $claims,
    ) {}

    public function create(CreateCustomerReturnData $data, User $actor): CustomerReturn
    {
        $this->authorization->authorize($actor, CustomerReturnPermission::Create);
        $validated = Validator::make((array) $data, [
            'orderId' => ['required', 'integer', 'exists:orders,id'], 'receivingWarehouseId' => ['nullable', 'integer', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'], 'items.*.order_fulfillment_item_id' => ['required', 'integer', 'distinct', 'exists:order_fulfillment_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'], 'items.*.return_reason' => ['required', 'string', 'in:'.implode(',', array_column(CustomerReturnReason::cases(), 'value'))],
            'items.*.reason_notes' => ['nullable', 'string', 'max:2000'], 'idempotencyKey' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        if ($existing = CustomerReturn::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }
        $reference = $this->references->nextCustomerReturnReference();

        return DB::transaction(function () use ($data, $validated, $actor, $reference): CustomerReturn {
            $order = Order::query()->with('fulfillment')->lockForUpdate()->findOrFail($data->orderId);
            if ($order->status !== OrderStatus::Fulfilled || $order->fulfillment === null) {
                throw new CustomerReturnException('Only fulfilled Orders can be returned.');
            }
            $this->authorization->authorize($actor, CustomerReturnPermission::Create);
            if (! app(OrderResponsibilityScopeService::class)->canAccessOrder($actor, $order)) {
                throw new AuthorizationException;
            }
            $fulfillmentWarehouse = Warehouse::query()->lockForUpdate()->findOrFail($order->warehouse_id);
            $marketplaceReturn = $fulfillmentWarehouse->location_type === InventoryLocationType::MarketplaceFulfilment;
            $warehouse = $data->receivingWarehouseId === null ? null : Warehouse::query()->lockForUpdate()->findOrFail($data->receivingWarehouseId);
            if ($marketplaceReturn && $warehouse !== null) {
                throw new CustomerReturnException('Marketplace Returns do not select a company receiving location until Removal is requested.');
            }
            if (! $marketplaceReturn && ($warehouse === null || ! $warehouse->status || in_array($warehouse->location_type, [InventoryLocationType::Transit, InventoryLocationType::MarketplaceFulfilment], true))) {
                throw new CustomerReturnException('Select an active company receiving location.');
            }
            $return = CustomerReturn::query()->create(['reference' => $reference, 'order_id' => $order->id, 'marketplace_platform_id' => $order->marketplace_platform_id,
                'fulfillment_warehouse_id' => $order->warehouse_id, 'status' => CustomerReturnStatus::Draft, 'return_source' => CustomerReturnSource::Manual,
                'receiving_warehouse_id' => $warehouse?->id, 'reported_at' => now(), 'created_by_user_id' => $actor->id, 'notes' => $data->notes, 'idempotency_key' => $data->idempotencyKey]);
            foreach ($validated['items'] as $input) {
                $fulfillmentItem = OrderFulfillmentItem::query()->with(['orderItem.product'])->lockForUpdate()->findOrFail($input['order_fulfillment_item_id']);
                if ($fulfillmentItem->fulfillment->order_id !== $order->id) {
                    throw new CustomerReturnException('A selected item does not belong to the fulfilled Order.');
                }
                $already = CustomerReturnItem::query()->where('order_fulfillment_item_id', $fulfillmentItem->id)->whereHas('customerReturn', fn ($q) => $q->where('status', '!=', CustomerReturnStatus::Cancelled->value))->sum('return_quantity');
                if ($already + $input['quantity'] > $fulfillmentItem->quantity) {
                    throw new CustomerReturnException('Return quantity exceeds the remaining fulfilled quantity.');
                }
                CustomerReturnItem::query()->create(['customer_return_id' => $return->id, 'order_item_id' => $fulfillmentItem->order_item_id, 'order_fulfillment_item_id' => $fulfillmentItem->id,
                    'product_id' => $fulfillmentItem->product_id, 'sku_snapshot' => $fulfillmentItem->orderItem->sku, 'product_name_snapshot' => $fulfillmentItem->orderItem->product_name,
                    'fulfilled_quantity_snapshot' => $fulfillmentItem->quantity, 'return_quantity' => $input['quantity'], 'inventory_unit_cost' => $fulfillmentItem->inventory_unit_cost,
                    'return_reason' => $input['return_reason'], 'reason_notes' => $input['reason_notes'] ?? null]);
            }
            $this->event($return, null, CustomerReturnStatus::Draft, $actor);
            $this->activity->log('customer_return.created', $actor, $return, ['return_reference' => $return->reference, 'order_id' => $order->id, 'item_count' => count($validated['items'])]);

            return $return->load('items');
        }, 5);
    }

    public function receive(CustomerReturn $return, User $actor): CustomerReturn
    {
        $this->authorization->authorize($actor, CustomerReturnPermission::Receive, $return);
        $return->loadMissing(['items', 'fulfillmentWarehouse.marketplacePlatform']);
        if ($return->status !== CustomerReturnStatus::Draft) {
            throw new CustomerReturnException('This Return has already been received or is no longer open.');
        }
        if ($return->fulfillmentWarehouse->location_type === InventoryLocationType::MarketplaceFulfilment
            && $return->fulfillmentWarehouse->marketplacePlatform?->return_handling_mode !== MarketplaceReturnHandlingMode::ReturnDirectlyToCompany) {
            throw new CustomerReturnException('Use Marketplace Disposition for this Platform Return.');
        }
        $movementRefs = [];
        foreach ($return->items->sortBy('id') as $item) {
            $movementRefs[$item->id] = $this->references->nextStockMovementReference();
        }

        return DB::transaction(function () use ($return, $actor, $movementRefs): CustomerReturn {
            $locked = CustomerReturn::query()->with(['items' => fn ($query) => $query->orderBy('id'), 'fulfillmentWarehouse.marketplacePlatform'])->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== CustomerReturnStatus::Draft) {
                throw new CustomerReturnException('This Return has already been received or is no longer open.');
            }
            if ($locked->fulfillmentWarehouse->location_type === InventoryLocationType::MarketplaceFulfilment
                && $locked->fulfillmentWarehouse->marketplacePlatform?->return_handling_mode !== MarketplaceReturnHandlingMode::ReturnDirectlyToCompany) {
                throw new CustomerReturnException('Use Marketplace Disposition for this Platform Return.');
            }
            $group = (string) Str::uuid();
            foreach ($locked->items as $item) {
                $inventory = $this->balances->lockOrCreate($item->product_id, $locked->receiving_warehouse_id);
                $before = $this->snapshot($inventory);
                $value = bcmul((string) $item->inventory_unit_cost, (string) $item->return_quantity, 4);
                $qBefore = $inventory->qc_pending_quantity;
                $vBefore = (string) $inventory->qc_pending_value;
                $inventory->forceFill(['qc_pending_quantity' => $qBefore + $item->return_quantity, 'qc_pending_value' => bcadd($vBefore, $value, 4)])->save();
                $movement = $this->movement($inventory, $item, $actor, $movementRefs[$item->id], $group, StockMovementType::CustomerReturnCompanyReceipt, $item->return_quantity, 0, 0, $before, (string) $item->inventory_unit_cost, 'Customer Return received into QC Pending');
                $this->bucket($movement, $item->return_quantity, $qBefore, $qBefore + $item->return_quantity, $value, $vBefore, (string) $inventory->qc_pending_value);
            }
            $locked->forceFill(['status' => CustomerReturnStatus::QcPending, 'received_at' => now(), 'received_by_user_id' => $actor->id])->save();
            $this->event($locked, CustomerReturnStatus::Draft, CustomerReturnStatus::QcPending, $actor);
            $this->activity->log('customer_return.received', $actor, $locked, ['return_reference' => $locked->reference, 'item_count' => $locked->items->count()]);

            return $locked->refresh();
        }, 5);
    }

    public function inspect(CustomerReturnItem $item, InspectCustomerReturnItemData $data, User $actor): CustomerReturn
    {
        $return = $item->customerReturn()->with('order')->firstOrFail();
        $this->authorization->authorize($actor, CustomerReturnPermission::Inspect, $return);
        $total = $data->sellableQuantity + $data->damagedQuantity;
        if ($total <= 0) {
            throw new CustomerReturnException('Enter a positive Sellable or Damaged quantity.');
        }
        if (CustomerReturnInspection::query()->where('posting_key', $data->postingKey)->exists()) {
            throw new CustomerReturnException('This inspection has already been posted.');
        }
        $movementRefs = [];
        if ($data->sellableQuantity > 0) {
            $movementRefs['sellable'] = $this->references->nextStockMovementReference();
        } if ($data->damagedQuantity > 0) {
            $movementRefs['damaged'] = $this->references->nextStockMovementReference();
        }
        $damageReference = $data->damagedQuantity > 0
            ? $this->references->nextDamagedStockReference()
            : null;
        $claimReference = $data->damagedQuantity > 0 && $this->claims->eligible($item)
            ? $this->references->nextSafetClaimReference()
            : null;

        return DB::transaction(function () use ($item, $data, $actor, $total, $movementRefs, $damageReference, $claimReference): CustomerReturn {
            $locked = CustomerReturnItem::query()->lockForUpdate()->findOrFail($item->id);
            $return = CustomerReturn::query()->with('items')->lockForUpdate()->findOrFail($locked->customer_return_id);
            if ($return->status !== CustomerReturnStatus::QcPending) {
                throw new CustomerReturnException('Only QC Pending Returns can be inspected.');
            }
            $inspected = (int) CustomerReturnInspection::query()->where('customer_return_item_id', $locked->id)
                ->orWhereIn('marketplace_return_removal_item_id', $locked->removalItems()->select('id'))->sum('quantity');
            $companyReceived = (int) $locked->removalItems()->sum('received_quantity');
            $marketplaceDispositioned = (int) $locked->marketplaceDispositions()->sum('quantity');
            $qcCeiling = $marketplaceDispositioned > 0 ? $companyReceived : $locked->return_quantity;
            if ($total > $qcCeiling - $inspected) {
                throw new CustomerReturnException('Sellable plus Damaged cannot exceed the remaining QC Pending quantity.');
            }
            $receivingWarehouseId = $locked->company_receiving_warehouse_id ?? $return->receiving_warehouse_id;
            $inventory = ProductInventory::query()->where('product_id', $locked->product_id)->where('warehouse_id', $receivingWarehouseId)->lockForUpdate()->firstOrFail();
            if ($inventory->qc_pending_quantity < $total) {
                throw new CustomerReturnException('Inspection exceeds QC Pending inventory.');
            }
            $before = $this->snapshot($inventory);
            $qBefore = $inventory->qc_pending_quantity;
            $vBefore = (string) $inventory->qc_pending_value;
            $carried = bcmul((string) $locked->inventory_unit_cost, (string) $total, 4);
            if (bccomp($carried, $vBefore, 4) > 0) {
                throw new CustomerReturnException('QC Pending value is insufficient for this inspection.');
            }
            $pooled = $inventory->available_quantity + $inventory->damaged_quantity;
            $newAverage = $this->costs->weightedAverage($pooled, $inventory->average_cost, $total, (string) $locked->inventory_unit_cost);
            $inventory->forceFill(['qc_pending_quantity' => $qBefore - $total, 'qc_pending_value' => bcsub($vBefore, $carried, 4), 'available_quantity' => $inventory->available_quantity + $data->sellableQuantity, 'damaged_quantity' => $inventory->damaged_quantity + $data->damagedQuantity, 'average_cost' => $newAverage])->save();
            $group = (string) Str::uuid();
            $ordinal = 0;
            foreach ([['result' => CustomerReturnInspectionResult::Sellable, 'quantity' => $data->sellableQuantity], ['result' => CustomerReturnInspectionResult::Damaged, 'quantity' => $data->damagedQuantity]] as $outcome) {
                $enum = $outcome['result'];
                $quantity = $outcome['quantity'];
                if ($quantity <= 0) {
                    continue;
                }
                $inspection = CustomerReturnInspection::query()->create(['customer_return_item_id' => $locked->id, 'quantity' => $quantity, 'result' => $enum, 'inspected_by_user_id' => $actor->id, 'inspected_at' => now(), 'notes' => $data->notes, 'posting_key' => $ordinal++ === 0 ? $data->postingKey : (string) Str::uuid()]);
                $type = $enum === CustomerReturnInspectionResult::Sellable ? StockMovementType::CustomerReturnQcSellable : StockMovementType::CustomerReturnQcDamaged;
                $movement = $this->movement($inventory, $inspection, $actor, $movementRefs[$enum->value], $group, $type, $quantity, $enum === CustomerReturnInspectionResult::Sellable ? $quantity : 0, $enum === CustomerReturnInspectionResult::Damaged ? $quantity : 0, $before, (string) $locked->inventory_unit_cost, 'Customer Return QC classification');
                $portion = bcmul((string) $locked->inventory_unit_cost, (string) $quantity, 4);
                $this->bucket($movement, -$quantity, $qBefore, $qBefore - $quantity, bcsub('0', $portion, 4), $vBefore, bcsub($vBefore, $portion, 4));
                $qBefore -= $quantity;
                $vBefore = bcsub($vBefore, $portion, 4);
                if ($enum === CustomerReturnInspectionResult::Damaged) {
                    $this->damagedStock->recordReturnQcDamage($inspection, $inventory, $actor, $damageReference, $claimReference);
                }
            }
            $complete = $return->items->every(function (CustomerReturnItem $ri): bool {
                $sellableAtMarketplace = (int) $ri->marketplaceDispositions()->where('result', 'sellable')->sum('quantity');

                $inspected = (int) CustomerReturnInspection::query()->where('customer_return_item_id', $ri->id)
                    ->orWhereIn('marketplace_return_removal_item_id', $ri->removalItems()->select('id'))->sum('quantity');

                return $sellableAtMarketplace + $inspected === $ri->return_quantity;
            });
            if ($complete) {
                $return->forceFill(['status' => CustomerReturnStatus::Completed, 'completed_at' => now()])->save();
                $this->event($return, CustomerReturnStatus::QcPending, CustomerReturnStatus::Completed, $actor);
                $this->activity->log('customer_return.completed', $actor, $return, ['return_reference' => $return->reference]);
            }
            $this->activity->log('customer_return.inspected', $actor, $return, ['return_reference' => $return->reference, 'product_id' => $locked->product_id, 'classified_quantity' => $total]);

            return $return->refresh();
        }, 5);
    }

    public function cancel(CustomerReturn $return, string $reason, User $actor): CustomerReturn
    {
        $this->authorization->authorize($actor, CustomerReturnPermission::Cancel, $return);
        if (trim($reason) === '') {
            throw new CustomerReturnException('Cancellation reason is required.');
        }

        return DB::transaction(function () use ($return, $reason, $actor) {
            $locked = CustomerReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== CustomerReturnStatus::Draft) {
                throw new CustomerReturnException('A Return cannot be cancelled after inventory receipt.');
            }$locked->forceFill(['status' => CustomerReturnStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason)])->save();
            $this->event($locked, CustomerReturnStatus::Draft, CustomerReturnStatus::Cancelled, $actor, trim($reason));
            $this->activity->log('customer_return.cancelled', $actor, $locked, ['return_reference' => $locked->reference, 'reason_recorded' => true]);

            return $locked;
        }, 5);
    }

    private function movement(ProductInventory $i, mixed $source, User $actor, string $ref, string $group, StockMovementType $type, int $qty, int $availableDelta, int $damagedDelta, array $before, string $cost, string $reason): StockMovement
    {
        return StockMovement::query()->create(['reference' => $ref, 'movement_group' => $group, 'product_inventory_id' => $i->id, 'product_id' => $i->product_id, 'warehouse_id' => $i->warehouse_id, 'movement_type' => $type, 'quantity' => $qty, 'available_delta' => $availableDelta, 'reserved_delta' => 0, 'damaged_delta' => $damagedDelta, 'available_before' => $before['available'], 'available_after' => $i->available_quantity, 'reserved_before' => $before['reserved'], 'reserved_after' => $i->reserved_quantity, 'damaged_before' => $before['damaged'], 'damaged_after' => $i->damaged_quantity, 'unit_cost' => $cost, 'average_cost_before' => $before['average'], 'average_cost_after' => $i->average_cost, 'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'reason' => $reason, 'actor_user_id' => $actor->id, 'occurred_at' => now()]);
    }

    private function bucket(StockMovement $m, int $delta, int $qb, int $qa, string $vd, string $vb, string $va): void
    {
        StockMovementBucketChange::query()->create(['stock_movement_id' => $m->id, 'bucket' => StockMovementBucket::QcPending, 'quantity_delta' => $delta, 'quantity_before' => $qb, 'quantity_after' => $qa, 'value_delta' => $vd, 'value_before' => $vb, 'value_after' => $va]);
    }

    private function snapshot(ProductInventory $i): array
    {
        return ['available' => $i->available_quantity, 'reserved' => $i->reserved_quantity, 'damaged' => $i->damaged_quantity, 'average' => $i->average_cost];
    }

    private function event(CustomerReturn $r, ?CustomerReturnStatus $from, CustomerReturnStatus $to, User $actor, ?string $reason = null): void
    {
        CustomerReturnStatusEvent::query()->create(['customer_return_id' => $r->id, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor->id, 'reason' => $reason, 'created_at' => now()]);
    }
}
