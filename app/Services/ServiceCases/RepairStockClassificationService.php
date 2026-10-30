<?php

namespace App\Services\ServiceCases;

use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\ProductCondition;
use App\Enums\StockMovementType;
use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\RepairStockClassification;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Models\WarrantyRepairStatusEvent;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RepairStockClassificationService
{
    public function __construct(
        private readonly WarrantyRepairAuthorization $repairs,
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly InventoryBalanceService $balances,
        private readonly InventoryAllocationService $allocations,
        private readonly RepairDestinationMatcher $matcher,
        private readonly WeightedAverageCostCalculator $costs,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function canApprove(User $actor, WarrantyRepair $repair): bool
    {
        return in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && $this->repairs->allows($actor, WarrantyRepairPermission::UpdateStatus, $repair)
            && $repair->isInternalCompanyOwnedRepair()
            && $repair->product_inventory_id !== null
            && $this->inventoryAuthorization->allows($actor, InventoryPermission::AdjustStock, $repair->inventory);
    }

    /** @return array<int, string> */
    public function destinationOptions(WarrantyRepair $repair, ProductCondition $condition): array
    {
        if (! $repair->isInternalCompanyOwnedRepair()) {
            return [];
        }

        $source = $repair->product;

        return $this->matcher->candidates($source, $condition)
            ->limit(200)->get()->filter(fn (Product $candidate): bool => $this->matcher->matches($source, $candidate, $condition))
            ->mapWithKeys(fn (Product $candidate): array => [$candidate->id => "{$candidate->sku} — {$candidate->name}"])->all();
    }

    /** @param array<string, mixed> $data */
    public function approve(WarrantyRepair $repair, array $data, User $actor): RepairStockClassification
    {
        if (! $this->canApprove($actor, $repair)) {
            throw new AuthorizationException;
        }
        $validated = Validator::make($data, [
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            'destination_product_id' => 'required|integer|exists:products,id',
            'allocation_account_id' => 'nullable|integer|exists:inventory_allocation_accounts,id',
            'reason' => 'required|string|min:10|max:2000',
            'replacement_evidence_reference' => 'nullable|string|max:255',
            'sealed_replacement_confirmed' => 'nullable|boolean',
            'idempotency_key' => 'required|uuid',
        ])->validate();
        $condition = ProductCondition::from($validated['condition']);
        if ($condition === ProductCondition::New
            && (! ($validated['sealed_replacement_confirmed'] ?? false) || blank($validated['replacement_evidence_reference'] ?? null))) {
            throw new WarrantyRepairException('New stock requires an explicitly verified sealed replacement and its evidence reference. An ordinarily repaired item cannot be New.');
        }

        if ($existing = $repair->stockClassification) {
            if ($existing->idempotency_key !== $validated['idempotency_key']) {
                throw new WarrantyRepairException('This repair has already been classified.');
            }

            return $existing;
        }

        // All references are reserved before the transaction. A rollback may leave gaps, never reused values.
        $classificationReference = $this->references->nextRepairClassificationReference();
        $sourceReference = $this->references->nextStockMovementReference();
        $destinationReference = $this->references->nextStockMovementReference();

        return DB::transaction(function () use ($repair, $validated, $condition, $actor, $classificationReference, $sourceReference, $destinationReference): RepairStockClassification {
            $locked = WarrantyRepair::query()->lockForUpdate()->findOrFail($repair->id);
            if ($already = $locked->stockClassification) {
                if ($already->idempotency_key !== $validated['idempotency_key']) {
                    throw new WarrantyRepairException('This repair has already been classified.');
                }

                return $already;
            }
            if ($locked->status !== WarrantyRepairStatus::PendingClassification || $locked->repair_qc_movement_id !== null) {
                throw new WarrantyRepairException('Only a QC-passed company-owned repair awaiting classification can be posted.');
            }
            if (! $this->canApprove($actor, $locked)) {
                throw new AuthorizationException;
            }
            $destinationProduct = Product::query()->findOrFail($validated['destination_product_id']);
            if (! $this->matcher->matches($locked->product, $destinationProduct, $condition)) {
                throw new WarrantyRepairException('Destination SKU must match the original product specifications and selected condition. Create the correct SKU using Products first.');
            }
            $source = $this->balances->lock($locked->product_inventory_id);
            if ($source->warehouse_id !== $locked->warehouse_id || $source->product_id !== $locked->product_id || $source->damaged_quantity < $locked->quantity) {
                throw new WarrantyRepairException('The linked damaged stock is unavailable for this classification.');
            }
            if ($source->average_cost === null) {
                throw new WarrantyRepairException('The damaged stock has no verified average cost. Reconcile its value before classifying.');
            }
            $destination = $this->balances->lockOrCreate($destinationProduct->id, $locked->warehouse_id);
            if (! $this->inventoryAuthorization->allows($actor, InventoryPermission::AdjustStock, $destination)) {
                throw new AuthorizationException;
            }

            $quantity = $locked->quantity;
            $unitCost = $source->average_cost;
            $movementGroup = (string) Str::uuid();
            $sourceMovement = $this->postMovement($source, $locked, $actor, $sourceReference, $movementGroup,
                StockMovementType::RepairClassificationOut, $quantity, 0, -$quantity, $unitCost, $source->average_cost, $classificationReference);
            if ($source->id === $destination->id) {
                $destination->refresh();
            }
            $newAverage = $source->id === $destination->id
                ? $source->average_cost
                : $this->costs->weightedAverage($destination->totalOnHand(), $destination->average_cost, $quantity, $unitCost);
            $destinationMovement = $this->postMovement($destination, $locked, $actor, $destinationReference, $movementGroup,
                StockMovementType::RepairClassificationIn, $quantity, $quantity, 0, $unitCost, $newAverage, $classificationReference);
            $allocationResolution = $this->allocations->classifyRepairedStock($locked, $source, $destination, $actor,
                $validated['allocation_account_id'] ?? null, $classificationReference);

            $classification = RepairStockClassification::query()->create([
                'reference' => $classificationReference,
                'warranty_repair_id' => $locked->id,
                'source_product_inventory_id' => $source->id,
                'destination_product_inventory_id' => $destination->id,
                'destination_product_id' => $destinationProduct->id,
                'condition' => $condition,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'source_movement_id' => $sourceMovement->id,
                'destination_movement_id' => $destinationMovement->id,
                'approved_by_user_id' => $actor->id,
                'reason' => $validated['reason'],
                'replacement_evidence_reference' => $validated['replacement_evidence_reference'] ?? null,
                'allocation_resolution' => $allocationResolution,
                'idempotency_key' => $validated['idempotency_key'],
                'approved_at' => now(),
            ]);
            $locked->forceFill(['repair_qc_movement_id' => $sourceMovement->id, 'status' => WarrantyRepairStatus::Completed, 'completed_at' => now()])->save();
            WarrantyRepairStatusEvent::query()->create([
                'warranty_repair_id' => $locked->id, 'from_status' => WarrantyRepairStatus::PendingClassification,
                'to_status' => WarrantyRepairStatus::Completed, 'note' => 'Stock classification '.$classificationReference,
                'changed_by_user_id' => $actor->id, 'changed_at' => now(),
            ]);
            $this->activity->log('warranty.stock_classified', $actor, $locked, [
                'repair_reference' => $locked->reference, 'classification_reference' => $classificationReference,
                'source_sku' => $locked->product->sku, 'destination_sku' => $destinationProduct->sku,
                'condition' => $condition->value, 'quantity' => $quantity,
                'source_movement' => $sourceReference, 'destination_movement' => $destinationReference,
            ]);

            return $classification;
        }, 5);
    }

    private function postMovement(ProductInventory $inventory, WarrantyRepair $repair, User $actor, string $reference, string $group,
        StockMovementType $type, int $quantity, int $availableDelta, int $damagedDelta, string $unitCost, string $averageAfter,
        string $classificationReference): StockMovement
    {
        $available = $inventory->available_quantity;
        $reserved = $inventory->reserved_quantity;
        $damaged = $inventory->damaged_quantity;
        $averageBefore = $inventory->average_cost;
        $inventory->forceFill([
            'available_quantity' => $available + $availableDelta,
            'damaged_quantity' => $damaged + $damagedDelta,
            'average_cost' => $averageAfter,
        ])->save();

        return StockMovement::query()->create([
            'reference' => $reference, 'movement_group' => $group,
            'product_inventory_id' => $inventory->id, 'product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id,
            'movement_type' => $type, 'quantity' => $quantity,
            'available_delta' => $availableDelta, 'reserved_delta' => 0, 'damaged_delta' => $damagedDelta,
            'available_before' => $available, 'available_after' => $inventory->available_quantity,
            'reserved_before' => $reserved, 'reserved_after' => $reserved,
            'damaged_before' => $damaged, 'damaged_after' => $inventory->damaged_quantity,
            'unit_cost' => $unitCost, 'average_cost_before' => $averageBefore, 'average_cost_after' => $averageAfter,
            'source_type' => $repair->getMorphClass(), 'source_id' => $repair->id,
            'reason' => 'Repair stock classification '.$classificationReference,
            'actor_user_id' => $actor->id, 'occurred_at' => now(),
        ]);
    }
}
