<?php

namespace App\Services\Inventory;

use App\DTOs\Inventory\InventoryMutationResult;
use App\DTOs\Inventory\MoveToDamagedData;
use App\DTOs\Inventory\PostOpeningStockData;
use App\DTOs\Inventory\ReleaseReservationData;
use App\DTOs\Inventory\ReserveInventoryData;
use App\DTOs\Inventory\RestoreDamagedData;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReservationKind;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductStatus;
use App\Enums\QuotationPermission;
use App\Enums\StockMovementType;
use App\Exceptions\DuplicateInventoryPostingException;
use App\Exceptions\ExactDecimalUnavailableException;
use App\Exceptions\InactiveInventorySubjectException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InventoryInvariantException;
use App\Models\Component;
use App\Models\InventoryReservation;
use App\Models\OpeningStockEntry;
use App\Models\OrderFulfillment;
use App\Models\OrderFulfillmentItem;
use App\Models\OrderItem;
use App\Models\OrderItemUpgradeSelection;
use App\Models\OrderUpgradeExecution;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptItem;
use App\Models\QuotationSourcingPosting;
use App\Models\StockMovement;
use App\Models\StockTransferItem;
use App\Models\UpgradeRecipeLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InventoryService
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly InventoryBalanceService $balances,
        private readonly WeightedAverageCostCalculator $costs,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function postOpeningStock(PostOpeningStockData $data, User $actor): InventoryMutationResult
    {
        $this->authorization->authorize($actor, InventoryPermission::PostOpeningStock);
        $validated = Validator::make([
            'product_id' => $data->productId,
            'warehouse_id' => $data->warehouseId,
            'available_quantity' => $data->availableQuantity,
            'damaged_quantity' => $data->damagedQuantity,
            'unit_cost' => trim($data->unitCost),
            'reason' => trim($data->reason),
            'idempotency_key' => $data->idempotencyKey,
        ], [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'available_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'damaged_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'unit_cost' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->after(function ($validator) use ($data): void {
            if ($data->availableQuantity + $data->damagedQuantity <= 0) {
                $validator->errors()->add('available_quantity', 'Opening Stock requires a positive available or damaged quantity.');
            }
        })->validate();

        if ($existing = OpeningStockEntry::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            if ($existing->product_id !== $validated['product_id']
                || $existing->warehouse_id !== $validated['warehouse_id']
                || $existing->available_quantity !== $validated['available_quantity']
                || $existing->damaged_quantity !== $validated['damaged_quantity']
                || $existing->unit_cost !== $this->costs->normalize($validated['unit_cost'])
                || $existing->reason !== $validated['reason']) {
                throw new DuplicateInventoryPostingException('The Opening Stock idempotency key belongs to a different request.');
            }

            return $this->resultFor($existing->product_id, $existing->warehouse_id, $existing);
        }

        $this->assertOpeningEligible($validated['product_id'], $validated['warehouse_id']);
        $openingReference = $this->references->nextOpeningStockReference();
        $movementReferences = collect();

        if ($validated['available_quantity'] > 0) {
            $movementReferences->push($this->references->nextStockMovementReference());
        }

        if ($validated['damaged_quantity'] > 0) {
            $movementReferences->push($this->references->nextStockMovementReference());
        }

        return DB::transaction(function () use ($validated, $actor, $openingReference, $movementReferences): InventoryMutationResult {
            $product = Product::query()->lockForUpdate()->findOrFail($validated['product_id']);
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($validated['warehouse_id']);
            $this->assertActive($product, $warehouse);

            if (OpeningStockEntry::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->exists()
                || StockMovement::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->exists()) {
                throw new DuplicateInventoryPostingException('Opening Stock is prohibited after any inventory history exists.');
            }

            $inventory = $this->balances->lockOrCreate($product->id, $warehouse->id);
            $movementGroup = (string) Str::uuid();
            $entry = OpeningStockEntry::query()->create([
                'reference' => $openingReference,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'available_quantity' => $validated['available_quantity'],
                'damaged_quantity' => $validated['damaged_quantity'],
                'unit_cost' => $this->costs->normalize($validated['unit_cost']),
                'reason' => $validated['reason'],
                'idempotency_key' => $validated['idempotency_key'],
                'movement_group' => $movementGroup,
                'posted_by_user_id' => $actor->id,
                'posted_at' => now(),
            ]);
            $movements = collect();
            $referenceIndex = 0;

            if ($validated['available_quantity'] > 0) {
                $movements->push($this->postInboundMovement(
                    $inventory,
                    $entry,
                    $actor,
                    $movementReferences[$referenceIndex++],
                    $movementGroup,
                    $validated['available_quantity'],
                    $validated['unit_cost'],
                    available: true,
                ));
            }

            if ($validated['damaged_quantity'] > 0) {
                $movements->push($this->postInboundMovement(
                    $inventory,
                    $entry,
                    $actor,
                    $movementReferences[$referenceIndex],
                    $movementGroup,
                    $validated['damaged_quantity'],
                    $validated['unit_cost'],
                    available: false,
                ));
            }

            $this->activity->log('inventory.opening_stock_posted', $actor, $entry, [
                'opening_stock_reference' => $entry->reference,
                'movement_group' => $movementGroup,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'available_quantity' => $validated['available_quantity'],
                'damaged_quantity' => $validated['damaged_quantity'],
                'reason_recorded' => true,
            ]);

            return new InventoryMutationResult($inventory->refresh(), $movements, $entry);
        }, 5);
    }

    public function reserve(ReserveInventoryData $data, User $actor): InventoryMutationResult
    {
        $validated = $this->validateQuantityRequest($data->productInventoryId, $data->quantity, $data->reason, $data->idempotencyKey);
        $inventoryForAuthorization = ProductInventory::query()->findOrFail($validated['product_inventory_id']);
        $this->authorization->authorize($actor, InventoryPermission::Reserve, $inventoryForAuthorization);

        if ($existing = InventoryReservation::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            if ($existing->product_inventory_id !== $validated['product_inventory_id']
                || $existing->quantity !== $validated['quantity']
                || $existing->reason !== $validated['reason']) {
                throw new DuplicateInventoryPostingException('The Reservation idempotency key belongs to a different request.');
            }

            return $this->resultFor($existing->product_id, $existing->warehouse_id, $existing);
        }

        $reservationReference = $this->references->nextInventoryReservationReference();
        $movementReference = $this->references->nextStockMovementReference();

        return DB::transaction(function () use ($validated, $actor, $reservationReference, $movementReference): InventoryMutationResult {
            $inventory = $this->balances->lock($validated['product_inventory_id']);
            $inventory->load(['product', 'warehouse']);
            $this->authorization->authorize($actor, InventoryPermission::Reserve, $inventory);
            $this->assertActive($inventory->product, $inventory->warehouse);

            if ($validated['quantity'] > $inventory->sellableQuantity()) {
                throw new InsufficientInventoryException('The reservation exceeds Sellable inventory.');
            }

            $reservation = InventoryReservation::query()->create([
                'reference' => $reservationReference,
                'product_inventory_id' => $inventory->id,
                'product_id' => $inventory->product_id,
                'warehouse_id' => $inventory->warehouse_id,
                'quantity' => $validated['quantity'],
                'status' => InventoryReservationStatus::Active,
                'reason' => $validated['reason'],
                'idempotency_key' => $validated['idempotency_key'],
                'reserved_by_user_id' => $actor->id,
                'reserved_at' => now(),
            ]);
            $before = $this->snapshot($inventory);
            $inventory->forceFill(['reserved_quantity' => $inventory->reserved_quantity + $validated['quantity']])->save();
            $movement = $this->createMovement(
                $inventory,
                $actor,
                $movementReference,
                (string) Str::uuid(),
                StockMovementType::Reservation,
                $validated['quantity'],
                0,
                $validated['quantity'],
                0,
                $before,
                $reservation,
                $validated['reason'],
                $validated['idempotency_key'],
            );

            $this->activity->log('inventory.reserved', $actor, $reservation, [
                'reservation_reference' => $reservation->reference,
                'movement_reference' => $movement->reference,
                'product_inventory_id' => $inventory->id,
                'quantity' => $validated['quantity'],
                'reason_recorded' => true,
            ]);

            return new InventoryMutationResult($inventory->refresh(), collect([$movement]), $reservation);
        }, 5);
    }

    public function release(InventoryReservation $reservation, ReleaseReservationData $data, User $actor): InventoryMutationResult
    {
        $validated = Validator::make([
            'reason' => trim($data->reason),
            'idempotency_key' => $data->idempotencyKey,
        ], [
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $inventoryForAuthorization = $reservation->inventory()->firstOrFail();
        $this->authorization->authorize($actor, InventoryPermission::ReleaseReservation, $inventoryForAuthorization);

        if ($reservation->status === InventoryReservationStatus::Released) {
            if ($reservation->release_idempotency_key === $validated['idempotency_key']) {
                return $this->resultFor($reservation->product_id, $reservation->warehouse_id, $reservation);
            }

            throw new DuplicateInventoryPostingException('This reservation has already been released.');
        }

        if (StockMovement::query()->where('idempotency_key', $validated['idempotency_key'])->exists()) {
            throw new DuplicateInventoryPostingException('The release idempotency key belongs to another inventory operation.');
        }

        $movementReference = $this->references->nextStockMovementReference();

        return DB::transaction(function () use ($reservation, $validated, $actor, $movementReference): InventoryMutationResult {
            $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $inventory = $this->balances->lock($reservation->product_inventory_id);
            $this->authorization->authorize($actor, InventoryPermission::ReleaseReservation, $inventory);

            if ($reservation->status === InventoryReservationStatus::Released) {
                if ($reservation->release_idempotency_key === $validated['idempotency_key']) {
                    return $this->resultFor($reservation->product_id, $reservation->warehouse_id, $reservation);
                }

                throw new DuplicateInventoryPostingException('This reservation has already been released.');
            }

            if ($inventory->reserved_quantity < $reservation->quantity) {
                throw new InventoryInvariantException('Reserved inventory is lower than the reservation being released.');
            }

            $before = $this->snapshot($inventory);
            $inventory->forceFill(['reserved_quantity' => $inventory->reserved_quantity - $reservation->quantity])->save();
            $reservation->forceFill([
                'status' => InventoryReservationStatus::Released,
                'release_idempotency_key' => $validated['idempotency_key'],
                'released_by_user_id' => $actor->id,
                'released_at' => now(),
            ])->save();
            $movement = $this->createMovement(
                $inventory,
                $actor,
                $movementReference,
                (string) Str::uuid(),
                StockMovementType::ReservationRelease,
                $reservation->quantity,
                0,
                -$reservation->quantity,
                0,
                $before,
                $reservation,
                $validated['reason'],
                $validated['idempotency_key'],
            );

            $this->activity->log('inventory.reservation_released', $actor, $reservation, [
                'reservation_reference' => $reservation->reference,
                'movement_reference' => $movement->reference,
                'product_inventory_id' => $inventory->id,
                'quantity' => $reservation->quantity,
                'reason_recorded' => true,
            ]);

            return new InventoryMutationResult($inventory->refresh(), collect([$movement]), $reservation);
        }, 5);
    }

    public function markDamaged(MoveToDamagedData $data, User $actor): InventoryMutationResult
    {
        return $this->moveDamaged($data->productInventoryId, $data->quantity, $data->reason, $data->idempotencyKey, $actor, restore: false);
    }

    public function restoreDamaged(RestoreDamagedData $data, User $actor): InventoryMutationResult
    {
        return $this->moveDamaged($data->productInventoryId, $data->quantity, $data->reason, $data->idempotencyKey, $actor, restore: true);
    }

    public function receivePurchaseItem(PurchaseReceiptItem $receiptItem, User $actor, string $movementReference, string $movementGroup): ?StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Purchase receipt inventory posting requires an active transaction.');
        }

        $valuationQuantity = $receiptItem->valuationQuantity();

        if ($valuationQuantity === 0) {
            return null;
        }

        $receiptItem->loadMissing('receipt.purchase');
        $purchase = $receiptItem->receipt->purchase;
        $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($purchase->warehouse_id);

        if (! $warehouse->status) {
            throw new InactiveInventorySubjectException('The Purchase Warehouse must be active before receiving.');
        }

        $inventory = $this->balances->lockOrCreate($receiptItem->product_id, $purchase->warehouse_id);
        $before = $this->snapshot($inventory);
        $newAverage = $this->costs->weightedAverage(
            $inventory->totalOnHand(),
            $inventory->average_cost,
            $valuationQuantity,
            $receiptItem->inventory_unit_cost,
        );
        $inventory->forceFill([
            'available_quantity' => $inventory->available_quantity + $receiptItem->accepted_quantity,
            'damaged_quantity' => $inventory->damaged_quantity + $receiptItem->damaged_quantity,
            'average_cost' => $newAverage,
        ])->save();

        return $this->createMovement(
            $inventory,
            $actor,
            $movementReference,
            $movementGroup,
            StockMovementType::PurchaseReceipt,
            $valuationQuantity,
            $receiptItem->accepted_quantity,
            0,
            $receiptItem->damaged_quantity,
            $before,
            $receiptItem,
            'Purchase receipt',
            $receiptItem->posting_key,
            $receiptItem->inventory_unit_cost,
        );
    }

    public function receiveQuotationSourcing(QuotationSourcingPosting $posting, User $actor, string $reference, string $group): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Quotation sourcing requires an active transaction.');
        }
        $quotation = $posting->quotationItem->quotation;
        $auth = app(QuotationAuthorization::class);
        $auth->authorize($actor, QuotationPermission::SourceInventory, $quotation);
        $auth->authorize($actor, QuotationPermission::ViewSourceCost, $quotation);
        $item = $posting->orderItem;
        $inventory = $this->balances->lock($posting->product_inventory_id);
        if ($inventory->product_id !== $item->product_id || $inventory->warehouse_id !== $item->order->warehouse_id
            || $item->quotation_item_id !== $posting->quotation_item_id
            || ! app(OrderResponsibilityScopeService::class)->canAccessProduct($actor, $inventory->product_id, null, $inventory->warehouse_id)) {
            throw new InventoryInvariantException('Quotation sourcing linkage or Responsibility scope is invalid.');
        }
        if ($existing = StockMovement::query()->where('idempotency_key', $posting->idempotency_key)->first()) {
            if ($existing->source_type !== $posting->getMorphClass() || $existing->source_id !== $posting->id) {
                throw new DuplicateInventoryPostingException('Sourcing posting key belongs to another inventory operation.');
            }

            return $existing;
        }
        $before = $this->snapshot($inventory);
        $average = $this->costs->weightedAverage($inventory->totalOnHand(), $inventory->average_cost, $posting->quantity, (string) $posting->purchase_unit_cost);
        $inventory->forceFill(['available_quantity' => $inventory->available_quantity + $posting->quantity, 'average_cost' => $average])->save();

        return $this->createMovement($inventory, $actor, $reference, $group, StockMovementType::QuotationSourcingReceipt,
            $posting->quantity, $posting->quantity, 0, 0, $before, $posting,
            'Stock received for accepted quotation', $posting->idempotency_key, (string) $posting->purchase_unit_cost);
    }

    public function reserveOrderItem(OrderItem $item, User $actor, string $reservationReference, string $movementReference, string $idempotencyKey, string $movementGroup): InventoryReservation
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Order reservation requires an active transaction.');
        }

        $inventory = ProductInventory::query()
            ->where('product_id', $item->product_id)
            ->where('warehouse_id', $item->order->warehouse_id)
            ->lockForUpdate()
            ->first();

        if ($inventory === null || $item->ordered_quantity > $inventory->sellableQuantity()) {
            throw new InsufficientInventoryException("Insufficient Sellable inventory for {$item->sku}.");
        }

        $before = $this->snapshot($inventory);
        $reservation = InventoryReservation::query()->create([
            'order_item_id' => $item->id,
            'order_item_upgrade_selection_id' => null,
            'upgrade_recipe_line_id' => null,
            'reservation_kind' => InventoryReservationKind::BaseProduct,
            'reservation_key' => "order-item:{$item->id}:base",
            'reference' => $reservationReference,
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => $item->ordered_quantity,
            'status' => InventoryReservationStatus::Active,
            'reason' => 'Sales Order reservation',
            'idempotency_key' => $idempotencyKey,
            'reserved_by_user_id' => $actor->id,
            'reserved_at' => now(),
        ]);
        $inventory->forceFill(['reserved_quantity' => $inventory->reserved_quantity + $item->ordered_quantity])->save();
        $movement = $this->createMovement(
            $inventory,
            $actor,
            $movementReference,
            $movementGroup,
            StockMovementType::Reservation,
            $item->ordered_quantity,
            0,
            $item->ordered_quantity,
            0,
            $before,
            $reservation,
            'Sales Order reservation',
            $idempotencyKey,
        );
        $this->activity->log('inventory.reserved', $actor, $reservation, [
            'reservation_reference' => $reservation->reference,
            'movement_reference' => $movement->reference,
            'order_item_id' => $item->id,
            'product_inventory_id' => $inventory->id,
            'quantity' => $item->ordered_quantity,
        ]);

        return $reservation;
    }

    public function reserveUpgradeComponent(
        OrderItemUpgradeSelection $selection,
        UpgradeRecipeLine $recipeLine,
        int $quantity,
        User $actor,
        string $reservationReference,
        string $movementReference,
        string $idempotencyKey,
        string $movementGroup,
    ): InventoryReservation {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Upgrade component reservation requires an active transaction.');
        }
        if ($quantity < 1 || $recipeLine->install_component_id === null) {
            throw new InventoryInvariantException('Upgrade component reservation details are invalid.');
        }

        $selection->loadMissing('orderItem.order');
        $component = Component::query()->with('product')->lockForUpdate()->findOrFail($recipeLine->install_component_id);
        $inventory = ProductInventory::query()
            ->where('product_id', $component->product_id)
            ->where('warehouse_id', $selection->orderItem->order->warehouse_id)
            ->lockForUpdate()->first();

        if ($component->product?->status !== ProductStatus::Active || $inventory === null || $quantity > $inventory->sellableQuantity()) {
            $available = $inventory?->sellableQuantity() ?? 0;
            throw new InsufficientInventoryException("{$component->specification} required: {$quantity}; available: {$available}.");
        }

        $before = $this->snapshot($inventory);
        $reservation = InventoryReservation::query()->create([
            'order_item_id' => $selection->order_item_id,
            'order_item_upgrade_selection_id' => $selection->id,
            'upgrade_recipe_line_id' => $recipeLine->id,
            'reservation_kind' => InventoryReservationKind::UpgradeComponent,
            'reservation_key' => "upgrade-selection:{$selection->id}:recipe-line:{$recipeLine->id}",
            'reference' => $reservationReference,
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => $quantity,
            'status' => InventoryReservationStatus::Active,
            'reason' => 'Sales Order upgrade component reservation',
            'idempotency_key' => $idempotencyKey,
            'reserved_by_user_id' => $actor->id,
            'reserved_at' => now(),
        ]);
        $inventory->forceFill(['reserved_quantity' => $inventory->reserved_quantity + $quantity])->save();
        $movement = $this->createMovement(
            $inventory, $actor, $movementReference, $movementGroup,
            StockMovementType::UpgradeComponentReservation, $quantity, 0, $quantity, 0,
            $before, $reservation, 'Sales Order upgrade component reservation', $idempotencyKey,
        );
        $this->activity->log('inventory.upgrade_component_reserved', $actor, $reservation, [
            'reservation_reference' => $reservation->reference,
            'movement_reference' => $movement->reference,
            'order_item_id' => $selection->order_item_id,
            'upgrade_selection_id' => $selection->id,
            'product_inventory_id' => $inventory->id,
            'quantity' => $quantity,
        ]);

        return $reservation;
    }

    public function releaseOrderReservation(InventoryReservation $reservation, User $actor, string $movementReference, string $idempotencyKey, string $movementGroup, string $reason): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Order reservation release requires an active transaction.');
        }

        $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);

        if ($reservation->status === InventoryReservationStatus::Released) {
            return;
        }

        $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($reservation->product_inventory_id);

        if ($inventory->reserved_quantity < $reservation->quantity) {
            throw new InventoryInvariantException('Reserved inventory is lower than the Order reservation being released.');
        }

        $before = $this->snapshot($inventory);
        $inventory->forceFill(['reserved_quantity' => $inventory->reserved_quantity - $reservation->quantity])->save();
        $reservation->forceFill([
            'status' => InventoryReservationStatus::Released,
            'release_idempotency_key' => $idempotencyKey,
            'released_by_user_id' => $actor->id,
            'released_at' => now(),
        ])->save();
        $movementType = $reservation->reservation_kind === InventoryReservationKind::UpgradeComponent
            ? StockMovementType::UpgradeComponentReservationRelease
            : StockMovementType::ReservationRelease;
        $movement = $this->createMovement(
            $inventory,
            $actor,
            $movementReference,
            $movementGroup,
            $movementType,
            $reservation->quantity,
            0,
            -$reservation->quantity,
            0,
            $before,
            $reservation,
            $reason,
            $idempotencyKey,
        );
        $this->activity->log('inventory.reservation_released', $actor, $reservation, [
            'reservation_reference' => $reservation->reference,
            'movement_reference' => $movement->reference,
            'order_item_id' => $reservation->order_item_id,
            'product_inventory_id' => $inventory->id,
            'quantity' => $reservation->quantity,
            'reason_recorded' => true,
        ]);
    }

    /** @return array{movement:StockMovement,unit_cost:string,total_value:string,inventory:ProductInventory} */
    public function fulfillUpgradeComponentReservation(
        InventoryReservation $reservation,
        OrderUpgradeExecution $execution,
        User $actor,
        string $movementReference,
        string $postingKey,
    ): array {
        return $this->consumeUpgradeComponent($reservation->product_inventory_id, $reservation->quantity, $execution, $actor, $movementReference, $postingKey, $reservation);
    }

    /** @return array{movement:StockMovement,unit_cost:string,total_value:string,inventory:ProductInventory} */
    public function consumeUpgradeComponentDirect(
        int $productId,
        int $warehouseId,
        int $quantity,
        OrderUpgradeExecution $execution,
        User $actor,
        string $movementReference,
        string $postingKey,
    ): array {
        $inventory = ProductInventory::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail();

        return $this->consumeUpgradeComponent($inventory->id, $quantity, $execution, $actor, $movementReference, $postingKey);
    }

    /** @return array{movement:StockMovement,inventory:ProductInventory,total_value:string} */
    public function recoverUpgradeComponent(
        Component $component,
        int $warehouseId,
        int $quantity,
        string $unitValue,
        bool $damaged,
        OrderUpgradeExecution $execution,
        User $actor,
        string $movementReference,
        string $postingKey,
    ): array {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Upgrade component recovery requires an active transaction.');
        }
        if ($quantity < 1 || bccomp($unitValue, '0', 4) < 0) {
            throw new InventoryInvariantException('Recovered component quantity or value is invalid.');
        }

        $inventory = $this->balances->lockOrCreate($component->product_id, $warehouseId);
        $before = $this->snapshot($inventory);
        $newAverage = $this->costs->weightedAverage($inventory->totalOnHand(), $inventory->average_cost, $quantity, $unitValue);
        $inventory->forceFill([
            'available_quantity' => $inventory->available_quantity + ($damaged ? 0 : $quantity),
            'damaged_quantity' => $inventory->damaged_quantity + ($damaged ? $quantity : 0),
            'average_cost' => $newAverage,
        ])->save();
        $movement = $this->createMovement(
            $inventory, $actor, $movementReference, $execution->selection->orderItem->order->fulfillment->movement_group,
            $damaged ? StockMovementType::UpgradeComponentRecoveryDamaged : StockMovementType::UpgradeComponentRecovery,
            $quantity, $damaged ? 0 : $quantity, 0, $damaged ? $quantity : 0,
            $before, $execution, $damaged ? 'OEM component recovered as damaged during upgrade' : 'OEM component recovered during upgrade',
            $postingKey, $unitValue,
        );

        return ['movement' => $movement, 'inventory' => $inventory, 'total_value' => bcmul((string) $quantity, $unitValue, 4)];
    }

    /** @return array{movement:StockMovement,unit_cost:string,total_value:string,inventory:ProductInventory} */
    private function consumeUpgradeComponent(
        int $inventoryId,
        int $quantity,
        OrderUpgradeExecution $execution,
        User $actor,
        string $movementReference,
        string $postingKey,
        ?InventoryReservation $reservation = null,
    ): array {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Upgrade component consumption requires an active transaction.');
        }

        $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($inventoryId);
        if ($inventory->average_cost === null) {
            throw new InventoryInvariantException('An installed Component has no Inventory Average Cost.');
        }
        if ($reservation === null) {
            if ($quantity > $inventory->sellableQuantity()) {
                throw new InsufficientInventoryException('Insufficient upgrade Component inventory.');
            }
        } else {
            $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->reservation_kind !== InventoryReservationKind::UpgradeComponent
                || $reservation->status !== InventoryReservationStatus::Active
                || $reservation->quantity !== $quantity
                || $reservation->product_inventory_id !== $inventory->id
                || $inventory->available_quantity < $quantity
                || $inventory->reserved_quantity < $quantity) {
                throw new InventoryInvariantException('The upgrade Component reservation is invalid.');
            }
        }

        $unitCost = (string) $inventory->average_cost;
        $totalValue = bcmul((string) $quantity, $unitCost, 4);
        $before = $this->snapshot($inventory);
        $reservedDelta = $reservation === null ? 0 : -$quantity;
        $inventory->forceFill([
            'available_quantity' => $inventory->available_quantity - $quantity,
            'reserved_quantity' => $inventory->reserved_quantity + $reservedDelta,
        ])->save();
        if ($reservation !== null) {
            $reservation->forceFill([
                'status' => InventoryReservationStatus::Fulfilled,
                'fulfilled_by_user_id' => $actor->id,
                'fulfilled_at' => now(),
            ])->save();
        }
        $movement = $this->createMovement(
            $inventory, $actor, $movementReference, $execution->selection->orderItem->order->fulfillment->movement_group,
            StockMovementType::UpgradeComponentInstall, $quantity, -$quantity, $reservedDelta, 0,
            $before, $execution, 'Component installed during Order upgrade', $postingKey, $unitCost,
        );

        return ['movement' => $movement, 'unit_cost' => $unitCost, 'total_value' => $totalValue, 'inventory' => $inventory];
    }

    public function fulfillOrderItem(
        OrderItem $orderItem,
        OrderFulfillment $fulfillment,
        User $actor,
        string $movementReference,
        string $postingKey,
        ?InventoryReservation $reservation = null,
    ): OrderFulfillmentItem {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Order fulfilment requires an active transaction.');
        }

        if (! function_exists('bcmul')) {
            throw new ExactDecimalUnavailableException('BCMath is required for immutable COGS calculation.');
        }

        $inventory = ProductInventory::query()
            ->where('product_id', $orderItem->product_id)
            ->where('warehouse_id', $orderItem->order->warehouse_id)
            ->lockForUpdate()
            ->firstOrFail();
        $quantity = $orderItem->ordered_quantity;

        if ($inventory->average_cost === null) {
            throw new InventoryInvariantException("{$orderItem->sku} has no Inventory Average Cost and cannot be fulfilled.");
        }

        if ($reservation === null) {
            if ($quantity > $inventory->sellableQuantity()) {
                throw new InsufficientInventoryException("Insufficient Sellable inventory for {$orderItem->sku}.");
            }
        } else {
            $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->order_item_id !== $orderItem->id
                || $reservation->status !== InventoryReservationStatus::Active
                || $reservation->quantity !== $quantity
                || $reservation->product_inventory_id !== $inventory->id
                || $reservation->product_id !== $orderItem->product_id
                || $reservation->warehouse_id !== $orderItem->order->warehouse_id) {
                throw new InventoryInvariantException('The Order reservation does not match the full fulfilment quantity.');
            }

            if ($inventory->available_quantity < $quantity || $inventory->reserved_quantity < $quantity) {
                throw new InventoryInvariantException('Inventory balances cannot satisfy the linked Order reservation.');
            }
        }

        $unitCost = $inventory->average_cost;
        $cogsTotal = bcmul((string) $quantity, $unitCost, 4);
        $fulfillmentItem = OrderFulfillmentItem::query()->create([
            'order_fulfillment_id' => $fulfillment->id,
            'order_item_id' => $orderItem->id,
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => $quantity,
            'inventory_unit_cost' => $unitCost,
            'cogs_total' => $cogsTotal,
            'posting_key' => $postingKey,
            'created_at' => now(),
        ]);
        $before = $this->snapshot($inventory);
        $reservedDelta = $reservation === null ? 0 : -$quantity;
        $inventory->forceFill([
            'available_quantity' => $inventory->available_quantity - $quantity,
            'reserved_quantity' => $inventory->reserved_quantity + $reservedDelta,
        ])->save();

        if ($reservation !== null) {
            $reservation->forceFill([
                'status' => InventoryReservationStatus::Fulfilled,
                'fulfilled_by_user_id' => $actor->id,
                'fulfilled_at' => now(),
            ])->save();
        }

        $movement = $this->createMovement(
            $inventory,
            $actor,
            $movementReference,
            $fulfillment->movement_group,
            StockMovementType::OrderFulfillment,
            $quantity,
            -$quantity,
            $reservedDelta,
            0,
            $before,
            $fulfillmentItem,
            'Sales Order fulfilment',
            $postingKey,
            $unitCost,
        );
        $this->activity->log('inventory.order_fulfilled', $actor, $fulfillmentItem, [
            'order_id' => $orderItem->order_id,
            'fulfillment_reference' => $fulfillment->reference,
            'movement_reference' => $movement->reference,
            'product_inventory_id' => $inventory->id,
            'quantity' => $quantity,
            'used_reservation' => $reservation !== null,
        ]);

        return $fulfillmentItem;
    }

    private function moveDamaged(int $inventoryId, int $quantity, string $reason, string $idempotencyKey, User $actor, bool $restore): InventoryMutationResult
    {
        $validated = $this->validateQuantityRequest($inventoryId, $quantity, $reason, $idempotencyKey);
        $permission = $restore ? InventoryPermission::RestoreDamaged : InventoryPermission::MarkDamaged;
        $inventoryForAuthorization = ProductInventory::query()->findOrFail($validated['product_inventory_id']);
        $this->authorization->authorize($actor, $permission, $inventoryForAuthorization);

        if ($existing = StockMovement::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            $expectedType = $restore ? StockMovementType::RestoreDamaged : StockMovementType::MarkDamaged;

            if ($existing->product_inventory_id !== $validated['product_inventory_id']
                || $existing->quantity !== $validated['quantity']
                || $existing->movement_type !== $expectedType
                || $existing->reason !== $validated['reason']) {
                throw new DuplicateInventoryPostingException('The idempotency key belongs to a different inventory operation.');
            }

            return new InventoryMutationResult($existing->inventory()->firstOrFail(), collect([$existing]));
        }

        $movementReference = $this->references->nextStockMovementReference();

        return DB::transaction(function () use ($validated, $actor, $restore, $permission, $movementReference): InventoryMutationResult {
            $inventory = $this->balances->lock($validated['product_inventory_id']);
            $this->authorization->authorize($actor, $permission, $inventory);
            $inventory->load(['product', 'warehouse']);

            if (! $restore) {
                $this->assertActive($inventory->product, $inventory->warehouse);
            }

            if ($restore && $validated['quantity'] > $inventory->damaged_quantity) {
                throw new InsufficientInventoryException('The restore quantity exceeds Damaged inventory.');
            }

            if (! $restore && $validated['quantity'] > $inventory->sellableQuantity()) {
                throw new InsufficientInventoryException('Only Sellable inventory can be marked damaged.');
            }

            $before = $this->snapshot($inventory);
            $availableDelta = $restore ? $validated['quantity'] : -$validated['quantity'];
            $damagedDelta = $restore ? -$validated['quantity'] : $validated['quantity'];
            $inventory->forceFill([
                'available_quantity' => $inventory->available_quantity + $availableDelta,
                'damaged_quantity' => $inventory->damaged_quantity + $damagedDelta,
            ])->save();
            $movement = $this->createMovement(
                $inventory,
                $actor,
                $movementReference,
                (string) Str::uuid(),
                $restore ? StockMovementType::RestoreDamaged : StockMovementType::MarkDamaged,
                $validated['quantity'],
                $availableDelta,
                0,
                $damagedDelta,
                $before,
                null,
                $validated['reason'],
                $validated['idempotency_key'],
            );
            $event = $restore ? 'inventory.damaged_restored' : 'inventory.marked_damaged';
            $this->activity->log($event, $actor, $inventory, [
                'movement_reference' => $movement->reference,
                'product_inventory_id' => $inventory->id,
                'quantity' => $validated['quantity'],
                'reason_recorded' => true,
            ]);

            return new InventoryMutationResult($inventory->refresh(), collect([$movement]));
        }, 5);
    }

    public function dispatchTransferItem(StockTransferItem $item, ProductInventory $inventory, User $actor, string $movementReference, string $movementGroup): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Stock Transfer dispatch requires an active transaction.');
        }

        if ($inventory->product_id !== $item->product_id || $item->quantity > $inventory->sellableQuantity()) {
            throw new InsufficientInventoryException("Insufficient Sellable inventory for {$item->sku}.");
        }

        if ($inventory->average_cost === null) {
            throw new InventoryInvariantException("Positive transferable inventory for {$item->sku} requires an average cost.");
        }

        $before = $this->snapshot($inventory);
        $inventory->forceFill(['available_quantity' => $inventory->available_quantity - $item->quantity])->save();
        $movement = $this->createMovement($inventory, $actor, $movementReference, $movementGroup, StockMovementType::TransferDispatch, $item->quantity, -$item->quantity, 0, 0, $before, $item, 'Stock Transfer dispatch', $item->posting_key, $inventory->average_cost);
        $this->activity->log('inventory.transfer_out', $actor, $item, ['transfer_id' => $item->stock_transfer_id, 'product_id' => $item->product_id, 'source_warehouse_id' => $inventory->warehouse_id, 'quantity' => $item->quantity]);

        return $movement;
    }

    public function receiveTransferItem(StockTransferItem $item, int $destinationWarehouseId, User $actor, string $movementReference, string $movementGroup): array
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Stock Transfer receipt requires an active transaction.');
        }

        $inventory = $this->balances->lockOrCreate($item->product_id, $destinationWarehouseId);
        $before = $this->snapshot($inventory);
        $newAverage = $this->costs->weightedAverage($inventory->totalOnHand(), $inventory->average_cost, $item->dispatched_quantity, (string) $item->dispatch_unit_cost);
        $inventory->forceFill(['available_quantity' => $inventory->available_quantity + $item->dispatched_quantity, 'average_cost' => $newAverage])->save();
        $movement = $this->createMovement($inventory, $actor, $movementReference, $movementGroup, StockMovementType::TransferReceipt, $item->dispatched_quantity, $item->dispatched_quantity, 0, 0, $before, $item, 'Stock Transfer receipt', null, (string) $item->dispatch_unit_cost);
        $this->activity->log('inventory.transfer_in', $actor, $item, ['transfer_id' => $item->stock_transfer_id, 'product_id' => $item->product_id, 'destination_warehouse_id' => $destinationWarehouseId, 'quantity' => $item->dispatched_quantity]);

        return [$inventory, $movement];
    }

    public function returnTransferItemToSource(StockTransferItem $item, User $actor, string $movementReference, string $movementGroup): array
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Stock Transfer return requires an active transaction.');
        }

        $inventory = $this->balances->lockOrCreate($item->product_id, $item->transfer->source_warehouse_id);
        $before = $this->snapshot($inventory);
        $newAverage = $this->costs->weightedAverage($inventory->totalOnHand(), $inventory->average_cost, $item->dispatched_quantity, (string) $item->dispatch_unit_cost);
        $inventory->forceFill(['available_quantity' => $inventory->available_quantity + $item->dispatched_quantity, 'average_cost' => $newAverage])->save();
        $movement = $this->createMovement($inventory, $actor, $movementReference, $movementGroup, StockMovementType::TransferReturnedToSource, $item->dispatched_quantity, $item->dispatched_quantity, 0, 0, $before, $item, 'Stock Transfer returned to source', null, (string) $item->dispatch_unit_cost);
        $this->activity->log('inventory.transfer_in', $actor, $item, ['transfer_id' => $item->stock_transfer_id, 'product_id' => $item->product_id, 'destination_warehouse_id' => $inventory->warehouse_id, 'quantity' => $item->dispatched_quantity, 'returned_to_source' => true]);

        return [$inventory, $movement];
    }

    private function postInboundMovement(ProductInventory $inventory, OpeningStockEntry $entry, User $actor, string $reference, string $movementGroup, int $quantity, string $unitCost, bool $available): StockMovement
    {
        $before = $this->snapshot($inventory);
        $newAverage = $this->costs->weightedAverage($inventory->totalOnHand(), $inventory->average_cost, $quantity, $unitCost);
        $availableDelta = $available ? $quantity : 0;
        $damagedDelta = $available ? 0 : $quantity;
        $inventory->forceFill([
            'available_quantity' => $inventory->available_quantity + $availableDelta,
            'damaged_quantity' => $inventory->damaged_quantity + $damagedDelta,
            'average_cost' => $newAverage,
        ])->save();

        return $this->createMovement(
            $inventory,
            $actor,
            $reference,
            $movementGroup,
            StockMovementType::OpeningStock,
            $quantity,
            $availableDelta,
            0,
            $damagedDelta,
            $before,
            $entry,
            $entry->reason,
            null,
            $this->costs->normalize($unitCost),
        );
    }

    /** @param array{available:int,reserved:int,damaged:int,average:?string} $before */
    private function createMovement(ProductInventory $inventory, User $actor, string $reference, string $movementGroup, StockMovementType $type, int $quantity, int $availableDelta, int $reservedDelta, int $damagedDelta, array $before, mixed $source, string $reason, ?string $idempotencyKey, ?string $unitCost = null): StockMovement
    {
        return StockMovement::query()->create([
            'reference' => $reference,
            'movement_group' => $movementGroup,
            'idempotency_key' => $idempotencyKey,
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'available_delta' => $availableDelta,
            'reserved_delta' => $reservedDelta,
            'damaged_delta' => $damagedDelta,
            'available_before' => $before['available'],
            'available_after' => $inventory->available_quantity,
            'reserved_before' => $before['reserved'],
            'reserved_after' => $inventory->reserved_quantity,
            'damaged_before' => $before['damaged'],
            'damaged_after' => $inventory->damaged_quantity,
            'unit_cost' => $unitCost,
            'average_cost_before' => $before['average'],
            'average_cost_after' => $inventory->average_cost,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'reason' => $reason,
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{available:int,reserved:int,damaged:int,average:?string} */
    private function snapshot(ProductInventory $inventory): array
    {
        return [
            'available' => $inventory->available_quantity,
            'reserved' => $inventory->reserved_quantity,
            'damaged' => $inventory->damaged_quantity,
            'average' => $inventory->average_cost,
        ];
    }

    /** @return array<string, mixed> */
    private function validateQuantityRequest(int $inventoryId, int $quantity, string $reason, string $idempotencyKey): array
    {
        return Validator::make([
            'product_inventory_id' => $inventoryId,
            'quantity' => $quantity,
            'reason' => trim($reason),
            'idempotency_key' => $idempotencyKey,
        ], [
            'product_inventory_id' => ['required', 'integer', 'exists:product_inventories,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
    }

    private function assertOpeningEligible(int $productId, int $warehouseId): void
    {
        $product = Product::query()->findOrFail($productId);
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $this->assertActive($product, $warehouse);

        if (OpeningStockEntry::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->exists()
            || StockMovement::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->exists()) {
            throw new DuplicateInventoryPostingException('Opening Stock is prohibited after any inventory history exists.');
        }
    }

    private function assertActive(Product $product, Warehouse $warehouse): void
    {
        if ($product->status !== ProductStatus::Active || ! $warehouse->status) {
            throw new InactiveInventorySubjectException('This inventory operation requires an active Product and Warehouse.');
        }
    }

    private function resultFor(int $productId, int $warehouseId, mixed $source): InventoryMutationResult
    {
        $inventory = ProductInventory::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
        $movements = StockMovement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->get();

        return new InventoryMutationResult($inventory, $movements, $source);
    }
}
