<?php

namespace App\Services\Returns;

use App\DTOs\Returns\CreateMarketplaceReturnRemovalData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\DTOs\Returns\MarketplaceRemovalItemData;
use App\Enums\CustomerReturnInspectionResult;
use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceDispositionResult;
use App\Enums\MarketplaceRemovalSourceStockType;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Enums\MarketplaceReturnPermission;
use App\Enums\MarketplaceReturnRemovalStatus;
use App\Enums\StockMovementBucket;
use App\Enums\StockMovementType;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnInspection;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnMarketplaceDisposition;
use App\Models\MarketplaceReturnRemoval;
use App\Models\MarketplaceReturnRemovalEvent;
use App\Models\MarketplaceReturnRemovalItem;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\StockMovementBucketChange;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\MarketplaceReturnAuthorization;
use App\Services\Claims\SafetClaimService;
use App\Services\Inventory\DamagedStockEventService;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceReturnService
{
    public function __construct(private readonly CustomerReturnAuthorization $returns, private readonly MarketplaceReturnAuthorization $authorization, private readonly InventoryBalanceService $balances, private readonly WeightedAverageCostCalculator $costs, private readonly ReferenceSequenceService $references, private readonly ActivityLogger $activity, private readonly OrderResponsibilityScopeService $responsibilities, private readonly DamagedStockEventService $damagedStock, private readonly SafetClaimService $claims) {}

    public function disposition(CustomerReturnItem $item, int $sellable, int $nonSellable, string $postingKey, User $actor, ?string $notes = null): CustomerReturn
    {
        $return = $item->customerReturn()->with(['order', 'fulfillmentWarehouse.marketplacePlatform'])->firstOrFail();
        $this->returns->authorize($actor, CustomerReturnPermission::RecordMarketplaceDisposition, $return);
        if ($sellable < 0 || $nonSellable < 0 || $sellable + $nonSellable < 1) {
            throw new CustomerReturnException('Enter a positive Sellable or Non-Sellable quantity.');
        }
        if (CustomerReturnMarketplaceDisposition::query()->whereIn('posting_key', [$postingKey.'-sellable', $postingKey.'-non-sellable'])->exists()) {
            return $return;
        }

        $movementReferences = [];
        if ($sellable > 0) {
            $movementReferences['sellable'] = $this->references->nextStockMovementReference();
        }
        if ($nonSellable > 0) {
            $movementReferences['non_sellable'] = $this->references->nextStockMovementReference();
        }

        return DB::transaction(function () use ($item, $sellable, $nonSellable, $postingKey, $actor, $notes, $movementReferences) {
            $locked = CustomerReturnItem::query()->lockForUpdate()->findOrFail($item->id);
            $return = CustomerReturn::query()->with(['order', 'fulfillmentWarehouse.marketplacePlatform', 'items'])->lockForUpdate()->findOrFail($locked->customer_return_id);
            $warehouse = $return->fulfillmentWarehouse;
            $platform = $warehouse->marketplacePlatform;
            if ($warehouse->location_type !== InventoryLocationType::MarketplaceFulfilment || ! $platform || $return->marketplace_platform_id !== $platform->id) {
                throw new CustomerReturnException('Marketplace disposition requires the original configured Marketplace Fulfilment location.');
            }if (! $platform->return_handling_mode) {
                throw new CustomerReturnException('Configure Return Handling Mode for this Marketplace Platform first.');
            }if ($platform->return_handling_mode === MarketplaceReturnHandlingMode::ReturnDirectlyToCompany && $nonSellable > 0) {
                throw new CustomerReturnException('This Platform uses direct company receipt for non-sellable returns.');
            }
            $disposed = (int) $locked->marketplaceDispositions()->sum('quantity');
            if ($disposed + $sellable + $nonSellable > $locked->return_quantity) {
                throw new CustomerReturnException('Marketplace disposition exceeds the remaining returned quantity.');
            }$inventory = ProductInventory::query()->where('product_id', $locked->product_id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->firstOrFail();
            $group = (string) Str::uuid();
            foreach ([[MarketplaceDispositionResult::Sellable, $sellable], [MarketplaceDispositionResult::NonSellable, $nonSellable]] as [$result,$qty]) {
                if (! $qty) {
                    continue;
                }$before = $this->snapshot($inventory);
                $qBefore = $inventory->marketplace_non_sellable_quantity;
                $vBefore = (string) $inventory->marketplace_non_sellable_value;
                $record = CustomerReturnMarketplaceDisposition::query()->create(['customer_return_item_id' => $locked->id, 'result' => $result, 'quantity' => $qty, 'recorded_by_user_id' => $actor->id, 'disposed_at' => now(), 'posting_key' => $postingKey.'-'.$result->value, 'notes' => $notes]);
                $cost = (string) $locked->inventory_unit_cost;
                if ($result === MarketplaceDispositionResult::Sellable) {
                    $pooled = $inventory->available_quantity + $inventory->damaged_quantity;
                    $inventory->forceFill(['available_quantity' => $inventory->available_quantity + $qty, 'average_cost' => $this->costs->weightedAverage($pooled, $inventory->average_cost, $qty, $cost)])->save();
                    $movement = $this->movement($inventory, $record, $actor, $movementReferences[$result->value], $group, StockMovementType::MarketplaceReturnSellable, $qty, $qty, 0, $before, $cost);
                    $event = 'customer_return.marketplace_sellable';
                } else {
                    $value = bcmul($cost, (string) $qty, 4);
                    $inventory->forceFill(['marketplace_non_sellable_quantity' => $qBefore + $qty, 'marketplace_non_sellable_value' => bcadd($vBefore, $value, 4)])->save();
                    $movement = $this->movement($inventory, $record, $actor, $movementReferences[$result->value], $group, StockMovementType::MarketplaceReturnNonSellable, $qty, 0, 0, $before, $cost);
                    $this->bucket($movement, StockMovementBucket::MarketplaceNonSellable, $qty, $qBefore, $qBefore + $qty, $value, $vBefore, (string) $inventory->marketplace_non_sellable_value);
                    $event = 'customer_return.marketplace_non_sellable';
                }
                $this->activity->log($event, $actor, $return, ['return_reference' => $return->reference, 'product_id' => $locked->product_id, 'quantity' => $qty]);
            }
            $this->completeIfTerminal($return, $actor);

            return $return->refresh();
        }, 5);
    }

    public function requestRemoval(CustomerReturnItem $item, int $quantity, int $destinationId, string $idempotencyKey, User $actor, ?string $external = null, ?string $notes = null): MarketplaceReturnRemoval
    {
        $return = $item->customerReturn()->firstOrFail();

        return $this->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $return->marketplace_platform_id,
            $return->fulfillment_warehouse_id,
            $destinationId,
            [new MarketplaceRemovalItemData($item->product_id, MarketplaceRemovalSourceStockType::NonSellable, $quantity, $item->id)],
            $idempotencyKey,
            $external,
            $notes,
        ), $actor);
    }

    public function requestBulkRemoval(CreateMarketplaceReturnRemovalData $data, User $actor): MarketplaceReturnRemoval
    {
        $this->authorization->authorize($actor, MarketplaceReturnPermission::RequestRemoval);
        if ($existing = MarketplaceReturnRemoval::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }

        $reference = $this->references->nextMarketplaceReturnRemovalReference();

        return DB::transaction(function () use ($data, $actor, $reference) {
            $source = Warehouse::query()->with('marketplacePlatform')->lockForUpdate()->findOrFail($data->sourceWarehouseId);
            $platform = $source->marketplacePlatform;
            if ($source->location_type !== InventoryLocationType::MarketplaceFulfilment || ! $platform || $platform->id !== $data->platformId) {
                throw new CustomerReturnException('Select a Marketplace Fulfilment location belonging to the selected Platform.');
            }
            $destination = Warehouse::query()->lockForUpdate()->findOrFail($data->destinationWarehouseId);
            if (! $destination->status || in_array($destination->location_type, [InventoryLocationType::Transit, InventoryLocationType::MarketplaceFulfilment], true)) {
                throw new CustomerReturnException('Select an active company receiving location.');
            }
            if ($data->items === []) {
                throw new CustomerReturnException('Add at least one Marketplace Removal item.');
            }
            $productIds = collect($data->items)->pluck('productId');
            $fingerprints = collect($data->items)->map(fn (MarketplaceRemovalItemData $line): string => implode(':', [$line->productId, $line->sourceStockType->value, $line->customerReturnItemId ?? 'none']));
            if ($fingerprints->duplicates()->isNotEmpty()) {
                throw new CustomerReturnException('The same Product, stock type, and Return provenance may appear only once.');
            }
            $inventories = ProductInventory::query()->where('warehouse_id', $source->id)->whereIn('product_id', $productIds)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $removal = MarketplaceReturnRemoval::query()->create(['reference' => $reference, 'marketplace_platform_id' => $platform->id, 'source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id, 'status' => MarketplaceReturnRemovalStatus::Requested, 'external_removal_reference' => $data->externalReference, 'requested_at' => now(), 'requested_by_user_id' => $actor->id, 'notes' => $data->notes, 'idempotency_key' => $data->idempotencyKey]);
            foreach ($data->items as $line) {
                if (! $line instanceof MarketplaceRemovalItemData || $line->quantity < 1) {
                    throw new CustomerReturnException('Every Marketplace Removal quantity must be positive.');
                }
                if (! $this->responsibilities->canAccessProduct($actor, $line->productId, $platform->id, $source->id)) {
                    throw new AuthorizationException;
                }
                $inventory = $inventories->get($line->productId);
                if (! $inventory) {
                    throw new CustomerReturnException('No source inventory exists for a selected Product.');
                }
                [$unitCost, $carriedValue] = $this->validateRemovalLine($line, $inventory, $source->id, $platform->id);
                $removal->items()->create(['customer_return_item_id' => $line->customerReturnItemId, 'product_id' => $line->productId, 'source_stock_type' => $line->sourceStockType, 'quantity' => $line->quantity, 'unit_cost' => $unitCost, 'carried_value' => $carriedValue, 'source_product_inventory_id' => $inventory->id, 'posting_key' => (string) Str::uuid()]);
            }
            $this->event($removal, null, MarketplaceReturnRemovalStatus::Requested, $actor);
            $this->activity->log('marketplace_return_removal.requested', $actor, $removal, ['removal_reference' => $reference, 'item_count' => count($data->items)]);

            return $removal->load('items');
        }, 5);
    }

    public function availableRemovalQuantity(
        int $sourceWarehouseId,
        int $productId,
        MarketplaceRemovalSourceStockType $sourceStockType,
        ?int $customerReturnItemId = null,
    ): int {
        $inventory = ProductInventory::query()
            ->select(['id', 'available_quantity', 'reserved_quantity', 'marketplace_non_sellable_quantity'])
            ->where('warehouse_id', $sourceWarehouseId)
            ->where('product_id', $productId)
            ->first();

        if (! $inventory) {
            return 0;
        }

        return $this->availableRemovalQuantityForInventory(
            $inventory,
            $sourceStockType,
            $customerReturnItemId,
        );
    }

    public function dispatch(MarketplaceReturnRemoval $removal, string $key, User $actor): MarketplaceReturnRemoval
    {
        $this->authorization->authorize($actor, MarketplaceReturnPermission::DispatchToCompany, $removal);
        if ($removal->status === MarketplaceReturnRemovalStatus::Dispatched && $removal->dispatch_idempotency_key === $key) {
            return $removal;
        }

        $movementReferences = $removal->items()
            ->orderBy('id')
            ->pluck('id')
            ->mapWithKeys(fn (int $itemId): array => [$itemId => $this->references->nextStockMovementReference()]);

        return DB::transaction(function () use ($removal, $key, $actor, $movementReferences) {
            $r = MarketplaceReturnRemoval::query()->with('items')->lockForUpdate()->findOrFail($removal->id);
            if ($r->status !== MarketplaceReturnRemovalStatus::Requested) {
                throw new CustomerReturnException('Only a requested Marketplace Removal can be dispatched.');
            }
            $group = (string) Str::uuid();
            foreach ($r->items as $i) {
                $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($i->source_product_inventory_id);
                $before = $this->snapshot($inventory);
                if ($i->source_stock_type === MarketplaceRemovalSourceStockType::Sellable) {
                    if ($inventory->sellableQuantity() < $i->quantity) {
                        throw new CustomerReturnException('Sellable inventory is insufficient for Marketplace Removal dispatch.');
                    }
                    $inventory->forceFill(['available_quantity' => $inventory->available_quantity - $i->quantity])->save();
                    $this->movement($inventory, $i, $actor, $movementReferences[$i->id], $group, StockMovementType::MarketplaceReturnRemovalDispatch, $i->quantity, -$i->quantity, 0, $before, (string) $i->unit_cost);
                } else {
                    if ($inventory->marketplace_non_sellable_quantity < $i->quantity) {
                        throw new CustomerReturnException('Marketplace non-sellable custody is insufficient for dispatch.');
                    }
                    $qb = $inventory->marketplace_non_sellable_quantity;
                    $vb = (string) $inventory->marketplace_non_sellable_value;
                    $value = (string) $i->carried_value;
                    if (bccomp($vb, $value, 4) < 0) {
                        throw new CustomerReturnException('Marketplace non-sellable carried value is insufficient.');
                    }
                    $inventory->forceFill(['marketplace_non_sellable_quantity' => $qb - $i->quantity, 'marketplace_non_sellable_value' => bcsub($vb, $value, 4)])->save();
                    $m = $this->movement($inventory, $i, $actor, $movementReferences[$i->id], $group, StockMovementType::MarketplaceReturnRemovalDispatch, $i->quantity, 0, 0, $before, (string) $i->unit_cost);
                    $this->bucket($m, StockMovementBucket::MarketplaceNonSellable, -$i->quantity, $qb, $qb - $i->quantity, bcsub('0', $value, 4), $vb, (string) $inventory->marketplace_non_sellable_value);
                }
                $i->forceFill(['dispatched_quantity' => $i->quantity])->save();
            }$r->forceFill(['status' => MarketplaceReturnRemovalStatus::Dispatched, 'dispatch_idempotency_key' => $key, 'dispatched_at' => now(), 'dispatched_by_user_id' => $actor->id])->save();
            $this->event($r, MarketplaceReturnRemovalStatus::Requested, MarketplaceReturnRemovalStatus::Dispatched, $actor);
            $this->activity->log('marketplace_return_removal.dispatched', $actor, $r, ['removal_reference' => $r->reference]);

            return $r->refresh();
        }, 5);
    }

    public function receive(MarketplaceReturnRemoval $removal, string $key, User $actor): MarketplaceReturnRemoval
    {
        $this->authorization->authorize($actor, MarketplaceReturnPermission::ReceiveCompany, $removal);
        $current = MarketplaceReturnRemoval::query()->findOrFail($removal->id);
        if ($current->status === MarketplaceReturnRemovalStatus::Received && $current->receive_idempotency_key === $key) {
            return $current;
        }

        $movementReferences = $current->items()
            ->orderBy('id')
            ->pluck('id')
            ->mapWithKeys(fn (int $itemId): array => [$itemId => $this->references->nextStockMovementReference()]);

        return DB::transaction(function () use ($current, $key, $actor, $movementReferences) {
            $r = MarketplaceReturnRemoval::query()->with('items.returnItem.customerReturn')->lockForUpdate()->findOrFail($current->id);
            if ($r->status !== MarketplaceReturnRemovalStatus::Dispatched) {
                throw new CustomerReturnException('Only a dispatched Marketplace Removal can be received.');
            }
            $group = (string) Str::uuid();
            foreach ($r->items as $i) {
                $inventory = $this->balances->lockOrCreate($i->product_id, $r->destination_warehouse_id);
                $before = $this->snapshot($inventory);
                $qb = $inventory->qc_pending_quantity;
                $vb = (string) $inventory->qc_pending_value;
                $value = (string) $i->carried_value;
                $inventory->forceFill(['qc_pending_quantity' => $qb + $i->quantity, 'qc_pending_value' => bcadd($vb, $value, 4)])->save();
                $m = $this->movement($inventory, $i, $actor, $movementReferences[$i->id], $group, StockMovementType::MarketplaceReturnCompanyReceipt, $i->quantity, 0, 0, $before, (string) $i->unit_cost);
                $this->bucket($m, StockMovementBucket::QcPending, $i->quantity, $qb, $qb + $i->quantity, $value, $vb, (string) $inventory->qc_pending_value);
                $i->forceFill(['received_quantity' => $i->quantity, 'destination_product_inventory_id' => $inventory->id])->save();
                if ($i->customer_return_item_id !== null) {
                    DB::table('customer_return_items')->where('id', $i->customer_return_item_id)->update(['company_receiving_warehouse_id' => $r->destination_warehouse_id, 'updated_at' => now()]);
                    $i->returnItem->customerReturn->forceFill(['status' => CustomerReturnStatus::QcPending, 'receiving_warehouse_id' => $r->destination_warehouse_id, 'received_at' => now(), 'received_by_user_id' => $actor->id])->save();
                }
            }$r->forceFill(['status' => MarketplaceReturnRemovalStatus::Received, 'receive_idempotency_key' => $key, 'received_at' => now(), 'received_by_user_id' => $actor->id])->save();
            $this->event($r, MarketplaceReturnRemovalStatus::Dispatched, MarketplaceReturnRemovalStatus::Received, $actor);
            $this->activity->log('marketplace_return_removal.received', $actor, $r, ['removal_reference' => $r->reference]);

            return $r->refresh();
        }, 5);
    }

    public function inspectRemovalItem(MarketplaceReturnRemovalItem $item, InspectCustomerReturnItemData $data, User $actor): void
    {
        $removal = $item->removal()->firstOrFail();
        $this->returns->authorize($actor, CustomerReturnPermission::Inspect);
        $this->authorization->authorize($actor, MarketplaceReturnPermission::View, $removal);
        $total = $data->sellableQuantity + $data->damagedQuantity;
        if ($total < 1) {
            throw new CustomerReturnException('Enter a positive Sellable or Damaged quantity.');
        }

        $movementReferences = [];
        if ($data->sellableQuantity > 0) {
            $movementReferences['sellable'] = $this->references->nextStockMovementReference();
        }
        if ($data->damagedQuantity > 0) {
            $movementReferences['damaged'] = $this->references->nextStockMovementReference();
        }
        $damageReference = $data->damagedQuantity > 0
            ? $this->references->nextDamagedStockReference()
            : null;
        $linkedReturnItem = $item->returnItem;
        $claimReference = $data->damagedQuantity > 0 && $linkedReturnItem !== null && $this->claims->eligible($linkedReturnItem)
            ? $this->references->nextSafetClaimReference()
            : null;

        DB::transaction(function () use ($item, $data, $actor, $total, $movementReferences, $damageReference, $claimReference): void {
            $locked = MarketplaceReturnRemovalItem::query()->with('returnItem.customerReturn')->lockForUpdate()->findOrFail($item->id);
            $removal = MarketplaceReturnRemoval::query()->lockForUpdate()->findOrFail($locked->marketplace_return_removal_id);
            if ($removal->status !== MarketplaceReturnRemovalStatus::Received || $locked->destination_product_inventory_id === null) {
                throw new CustomerReturnException('Only a received Marketplace Removal item can be inspected.');
            }
            if (CustomerReturnInspection::query()->where('posting_key', $data->postingKey)->exists()) {
                throw new CustomerReturnException('This inspection has already been posted.');
            }
            $inspected = (int) CustomerReturnInspection::query()->where('marketplace_return_removal_item_id', $locked->id)
                ->when($locked->customer_return_item_id !== null, fn ($query) => $query->orWhere('customer_return_item_id', $locked->customer_return_item_id)
                    ->orWhereIn('marketplace_return_removal_item_id', MarketplaceReturnRemovalItem::query()->where('customer_return_item_id', $locked->customer_return_item_id)->select('id')))
                ->sum('quantity');
            if ($inspected + $total > $locked->received_quantity) {
                throw new CustomerReturnException('Inspection exceeds the remaining QC Pending quantity.');
            }
            $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($locked->destination_product_inventory_id);
            if ($inventory->qc_pending_quantity < $total) {
                throw new CustomerReturnException('Inspection exceeds QC Pending inventory.');
            }
            $before = $this->snapshot($inventory);
            $qBefore = $inventory->qc_pending_quantity;
            $vBefore = (string) $inventory->qc_pending_value;
            $carried = bcmul((string) $locked->unit_cost, (string) $total, 4);
            if (bccomp($carried, $vBefore, 4) > 0) {
                throw new CustomerReturnException('QC Pending value is insufficient for this inspection.');
            }
            $pooled = $inventory->available_quantity + $inventory->damaged_quantity;
            $inventory->forceFill([
                'qc_pending_quantity' => $qBefore - $total,
                'qc_pending_value' => bcsub($vBefore, $carried, 4),
                'available_quantity' => $inventory->available_quantity + $data->sellableQuantity,
                'damaged_quantity' => $inventory->damaged_quantity + $data->damagedQuantity,
                'average_cost' => $this->costs->weightedAverage($pooled, $inventory->average_cost, $total, (string) $locked->unit_cost),
            ])->save();
            $group = (string) Str::uuid();
            $ordinal = 0;
            foreach ([[CustomerReturnInspectionResult::Sellable, $data->sellableQuantity], [CustomerReturnInspectionResult::Damaged, $data->damagedQuantity]] as [$result, $quantity]) {
                if ($quantity < 1) {
                    continue;
                }
                $inspection = CustomerReturnInspection::query()->create(['marketplace_return_removal_item_id' => $locked->id, 'quantity' => $quantity, 'result' => $result, 'inspected_by_user_id' => $actor->id, 'inspected_at' => now(), 'notes' => $data->notes, 'posting_key' => $ordinal++ === 0 ? $data->postingKey : (string) Str::uuid()]);
                $type = $result === CustomerReturnInspectionResult::Sellable ? StockMovementType::CustomerReturnQcSellable : StockMovementType::CustomerReturnQcDamaged;
                $movement = $this->movement($inventory, $inspection, $actor, $movementReferences[$result->value], $group, $type, $quantity, $result === CustomerReturnInspectionResult::Sellable ? $quantity : 0, $result === CustomerReturnInspectionResult::Damaged ? $quantity : 0, $before, (string) $locked->unit_cost);
                $portion = bcmul((string) $locked->unit_cost, (string) $quantity, 4);
                $this->bucket($movement, StockMovementBucket::QcPending, -$quantity, $qBefore, $qBefore - $quantity, bcsub('0', $portion, 4), $vBefore, bcsub($vBefore, $portion, 4));
                $qBefore -= $quantity;
                $vBefore = bcsub($vBefore, $portion, 4);
                if ($result === CustomerReturnInspectionResult::Damaged) {
                    $this->damagedStock->recordReturnQcDamage($inspection, $inventory, $actor, $damageReference, $claimReference);
                }
            }
            if ($locked->customer_return_item_id !== null) {
                $this->completeIfTerminal($locked->returnItem->customerReturn->load('items'), $actor);
            }
            $this->activity->log('marketplace_return_removal.inspected', $actor, $removal, ['removal_reference' => $removal->reference, 'product_id' => $locked->product_id, 'classified_quantity' => $total]);
        }, 5);
    }

    /** @return array{0:string,1:string} */
    private function validateRemovalLine(MarketplaceRemovalItemData $line, ProductInventory $inventory, int $sourceWarehouseId, int $platformId): array
    {
        $available = $this->availableRemovalQuantityForInventory(
            $inventory,
            $line->sourceStockType,
            $line->customerReturnItemId,
        );

        if ($line->quantity > $available) {
            throw new CustomerReturnException("Only {$available} units are available for removal.");
        }

        if ($line->sourceStockType === MarketplaceRemovalSourceStockType::Sellable) {
            if ($line->customerReturnItemId !== null) {
                throw new CustomerReturnException('General Marketplace Sellable removal lines must not link a Customer Return Item.');
            }
            if ($inventory->average_cost === null) {
                throw new CustomerReturnException('Marketplace Sellable inventory cannot be removed until its valuation cost is available.');
            }
            $unitCost = (string) $inventory->average_cost;

            return [$unitCost, bcmul($unitCost, (string) $line->quantity, 4)];
        }

        if ($line->customerReturnItemId === null) {
            throw new CustomerReturnException('Marketplace Non-Sellable removal requires Customer Return provenance.');
        }
        $returnItem = CustomerReturnItem::query()->with('customerReturn')->lockForUpdate()->findOrFail($line->customerReturnItemId);
        if ($returnItem->product_id !== $line->productId || $returnItem->customerReturn->fulfillment_warehouse_id !== $sourceWarehouseId || $returnItem->customerReturn->marketplace_platform_id !== $platformId) {
            throw new CustomerReturnException('The selected Customer Return Item does not match the Marketplace source and Product.');
        }
        $unitCost = (string) $returnItem->inventory_unit_cost;

        return [$unitCost, bcmul($unitCost, (string) $line->quantity, 4)];
    }

    private function availableRemovalQuantityForInventory(
        ProductInventory $inventory,
        MarketplaceRemovalSourceStockType $sourceStockType,
        ?int $customerReturnItemId,
    ): int {
        $requested = (int) MarketplaceReturnRemovalItem::query()
            ->where('source_product_inventory_id', $inventory->id)
            ->where('source_stock_type', $sourceStockType->value)
            ->whereHas('removal', fn ($query) => $query->where('status', MarketplaceReturnRemovalStatus::Requested->value))
            ->sum('quantity');

        if ($sourceStockType === MarketplaceRemovalSourceStockType::Sellable) {
            return max(0, $inventory->sellableQuantity() - $requested);
        }

        $available = max(0, $inventory->marketplace_non_sellable_quantity - $requested);
        if ($customerReturnItemId === null) {
            return $available;
        }

        $eligible = (int) CustomerReturnMarketplaceDisposition::query()
            ->where('customer_return_item_id', $customerReturnItemId)
            ->where('result', MarketplaceDispositionResult::NonSellable->value)
            ->sum('quantity');
        $provenanceCommitted = (int) MarketplaceReturnRemovalItem::query()
            ->where('customer_return_item_id', $customerReturnItemId)
            ->whereHas('removal', fn ($query) => $query->where('status', '!=', MarketplaceReturnRemovalStatus::Cancelled->value))
            ->sum('quantity');

        return min($available, max(0, $eligible - $provenanceCommitted));
    }

    private function completeIfTerminal(CustomerReturn $r, User $actor): void
    {
        $terminal = $r->items->every(function ($i) {
            $sell = (int) $i->marketplaceDispositions()->where('result', 'sellable')->sum('quantity');
            $inspected = (int) CustomerReturnInspection::query()->where('customer_return_item_id', $i->id)
                ->orWhereIn('marketplace_return_removal_item_id', $i->removalItems()->select('id'))->sum('quantity');

            return $sell + $inspected === $i->return_quantity;
        });
        if ($terminal) {
            $r->forceFill(['status' => CustomerReturnStatus::Completed, 'completed_at' => now()])->save();
            $this->activity->log('customer_return.completed', $actor, $r, ['return_reference' => $r->reference]);
        }
    }

    private function snapshot(ProductInventory $i): array
    {
        return ['available' => $i->available_quantity, 'reserved' => $i->reserved_quantity, 'damaged' => $i->damaged_quantity, 'average' => $i->average_cost];
    }

    private function movement(ProductInventory $i, mixed $s, User $a, string $ref, string $group, StockMovementType $t, int $q, int $ad, int $dd, array $b, string $cost): StockMovement
    {
        return StockMovement::query()->create(['reference' => $ref, 'movement_group' => $group, 'product_inventory_id' => $i->id, 'product_id' => $i->product_id, 'warehouse_id' => $i->warehouse_id, 'movement_type' => $t, 'quantity' => $q, 'available_delta' => $ad, 'reserved_delta' => 0, 'damaged_delta' => $dd, 'available_before' => $b['available'], 'available_after' => $i->available_quantity, 'reserved_before' => $b['reserved'], 'reserved_after' => $i->reserved_quantity, 'damaged_before' => $b['damaged'], 'damaged_after' => $i->damaged_quantity, 'unit_cost' => $cost, 'average_cost_before' => $b['average'], 'average_cost_after' => $i->average_cost, 'source_type' => $s->getMorphClass(), 'source_id' => $s->getKey(), 'actor_user_id' => $a->id, 'occurred_at' => now()]);
    }

    private function bucket(StockMovement $m, StockMovementBucket $b, int $d, int $qb, int $qa, string $vd, string $vb, string $va): void
    {
        StockMovementBucketChange::query()->create(['stock_movement_id' => $m->id, 'bucket' => $b, 'quantity_delta' => $d, 'quantity_before' => $qb, 'quantity_after' => $qa, 'value_delta' => $vd, 'value_before' => $vb, 'value_after' => $va]);
    }

    private function event(MarketplaceReturnRemoval $r, ?MarketplaceReturnRemovalStatus $f, MarketplaceReturnRemovalStatus $t, User $a): void
    {
        MarketplaceReturnRemovalEvent::query()->create(['marketplace_return_removal_id' => $r->id, 'from_status' => $f, 'to_status' => $t, 'actor_user_id' => $a->id, 'created_at' => now()]);
    }
}
