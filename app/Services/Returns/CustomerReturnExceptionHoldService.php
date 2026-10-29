<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnInspectionResult;
use App\Enums\InventoryReservationStatus;
use App\Enums\StockMovementType;
use App\Models\CustomerReturnExceptionCase;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationReservationLine;
use App\Models\InventoryReservation;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CustomerReturnExceptionHoldService
{
    public function __construct(private readonly ReferenceSequenceService $references) {}

    /** Reserve only a provably isolated QC PASS unit; shared SKU stock is otherwise left untouched. */
    public function assess(int $caseId, User $actor): void
    {
        // The scheduler may revisit a case after an interrupted callback. Avoid
        // allocating another reference for an already completed assessment.
        if (! CustomerReturnExceptionCase::query()->whereKey($caseId)
            ->whereNull('resolved_at')->where('protection_status', 'assessment_pending')->exists()) {
            return;
        }

        try {
            // References must be allocated before opening the business transaction.
            $reservationReference = $this->references->nextInventoryReservationReference();
            $movementReference = $this->references->nextStockMovementReference();
            DB::transaction(function () use ($caseId, $actor, $reservationReference, $movementReference): void {
                $case = CustomerReturnExceptionCase::query()->lockForUpdate()->findOrFail($caseId);
                if ($case->resolved_at !== null || $case->protection_status !== 'assessment_pending') {
                    return;
                }
                $item = $case->item;
                $inspection = $item->inspections()->where('result', CustomerReturnInspectionResult::Sellable)->sole();
                $quantity = $inspection->quantity;
                $return = $item->customerReturn;
                $inventory = ProductInventory::query()->where('product_id', $item->product_id)
                    ->where('warehouse_id', $return->receiving_warehouse_id)->lockForUpdate()->first();
                $posting = StockMovement::query()->where('source_type', $inspection->getMorphClass())
                    ->where('source_id', $inspection->id)
                    ->where('movement_type', StockMovementType::CustomerReturnQcSellable)->first();
                $reason = $this->isIsolated($case, $item->return_quantity, $quantity, $inventory, $posting)
                    ? null : 'The returned unit cannot be isolated from other SKU stock or later movements.';
                $balances = $reason === null ? InventoryAllocationBalance::query()
                    ->where('product_inventory_id', $inventory->id)->orderBy('id')->lockForUpdate()->get() : collect();
                if ($reason === null && ((int) $balances->sum('allocated_quantity') !== $quantity
                    || (int) $balances->sum('reserved_quantity') !== 0)) {
                    $reason = 'Allocation ownership cannot be isolated safely.';
                }
                if ($reason !== null) {
                    $case->forceFill(['status' => 'escalation_required', 'protection_status' => 'manual_reconciliation',
                        'protection_reason' => $reason])->save();
                    $this->event($case, $actor, 'hold_not_safe', ['reason' => $reason]);

                    return;
                }

                $reservation = InventoryReservation::query()->create([
                    'reference' => $reservationReference, 'product_inventory_id' => $inventory->id,
                    'product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id,
                    'quantity' => $quantity, 'status' => InventoryReservationStatus::Active,
                    'reason' => 'Temporary hold for Customer Return exception '.$return->reference,
                    'idempotency_key' => $case->hold_key, 'reserved_by_user_id' => $actor->id,
                    'reserved_at' => now(),
                ]);
                foreach ($balances as $balance) {
                    if ($balance->allocated_quantity === 0) {
                        continue;
                    }
                    $balance->increment('reserved_quantity', $balance->allocated_quantity);
                    InventoryAllocationReservationLine::query()->create([
                        'inventory_reservation_id' => $reservation->id, 'account_id' => $balance->account_id,
                        'quantity' => $balance->allocated_quantity, 'status' => 'reserved',
                    ]);
                    $this->allocationEvent('return_exception_hold', $case, $inventory, $actor, $balance->account_id, $balance->allocated_quantity);
                }
                $before = $inventory->reserved_quantity;
                $inventory->forceFill(['reserved_quantity' => $before + $quantity])->save();
                $this->movement($inventory, $reservation, $actor, $movementReference,
                    StockMovementType::Reservation, $quantity, 0, $quantity, $before,
                    'Temporary hold for disputed returned unit');
                $case->forceFill(['status' => 'awaiting_review', 'protection_status' => 'held',
                    'inventory_reservation_id' => $reservation->id, 'protection_reason' => null])->save();
                $this->event($case, $actor, 'inventory_held', ['reservation_id' => $reservation->id, 'quantity' => $quantity]);
            }, 5);
        } catch (Throwable $exception) {
            // Evidence is already immutable and committed. Fail closed without
            // inventing an inventory adjustment or concealing the urgent review.
            Log::warning('Return exception hold requires manual review.', [
                'case_id' => $caseId, 'exception' => $exception::class,
            ]);
            try {
                DB::transaction(function () use ($caseId, $actor): void {
                    $case = CustomerReturnExceptionCase::query()->lockForUpdate()->find($caseId);
                    if ($case === null || $case->protection_status !== 'assessment_pending') {
                        return;
                    }
                    $case->forceFill(['status' => 'escalation_required',
                        'protection_status' => 'manual_reconciliation',
                        'protection_reason' => 'The inventory hold could not be verified.'])->save();
                    $this->event($case, $actor, 'hold_assessment_failed', [
                        'reason' => 'The inventory hold could not be verified.',
                    ]);
                });
            } catch (Throwable $recordingFailure) {
                Log::error('Return exception hold failure could not be recorded.', [
                    'case_id' => $caseId, 'exception' => $recordingFailure::class,
                ]);
            }
        }
    }

    /** Must run inside the Owner/Admin decision transaction with a pre-reserved reference. */
    public function release(CustomerReturnExceptionCase $case, User $actor, string $reference): ProductInventory
    {
        $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($case->inventory_reservation_id);
        $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($reservation->product_inventory_id);
        if ($reservation->status !== InventoryReservationStatus::Active || $inventory->reserved_quantity < $reservation->quantity) {
            throw new \RuntimeException('The exception hold no longer matches the reserved inventory.');
        }
        $lines = InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')->orderBy('id')->lockForUpdate()->get();
        if ((int) $lines->sum('quantity') !== $reservation->quantity) {
            throw new \RuntimeException('The exception allocation hold is incomplete.');
        }
        foreach ($lines as $line) {
            $balance = InventoryAllocationBalance::query()->where('account_id', $line->account_id)
                ->where('product_inventory_id', $inventory->id)->lockForUpdate()->firstOrFail();
            if ($balance->reserved_quantity < $line->quantity) {
                throw new \RuntimeException('The exception allocation hold cannot be released safely.');
            }
            $balance->decrement('reserved_quantity', $line->quantity);
            $line->forceFill(['status' => 'released'])->save();
            $this->allocationEvent('return_exception_hold_released', $case, $inventory, $actor, $balance->account_id, $line->quantity);
        }
        $before = $inventory->reserved_quantity;
        $inventory->forceFill(['reserved_quantity' => $before - $reservation->quantity])->save();
        $reservation->forceFill(['status' => InventoryReservationStatus::Released,
            'release_idempotency_key' => (string) Str::uuid(), 'released_by_user_id' => $actor->id,
            'released_at' => now()])->save();
        $this->movement($inventory, $reservation, $actor, $reference, StockMovementType::ReservationRelease,
            $reservation->quantity, 0, -$reservation->quantity, $before, 'Owner/Admin released the Return exception hold');
        $this->event($case, $actor, 'inventory_hold_released', ['reservation_id' => $reservation->id]);

        return $inventory;
    }

    /** Correct only the allocation lines that belonged to this isolated false QC PASS. */
    public function removeMisclassifiedOriginal(CustomerReturnExceptionCase $case, ProductInventory $inventory, User $actor): void
    {
        $lines = InventoryAllocationReservationLine::query()
            ->where('inventory_reservation_id', $case->inventory_reservation_id)
            ->where('status', 'released')->orderBy('id')->lockForUpdate()->get();
        if ((int) $lines->sum('quantity') !== $case->affected_quantity) {
            throw new \RuntimeException('The disputed original-SKU allocation cannot be reconciled.');
        }
        foreach ($lines as $line) {
            $balance = InventoryAllocationBalance::query()->where('account_id', $line->account_id)
                ->where('product_inventory_id', $inventory->id)->lockForUpdate()->firstOrFail();
            if ($balance->allocated_quantity < $line->quantity || $balance->reserved_quantity !== 0) {
                throw new \RuntimeException('Another allocation prevents this original-SKU correction.');
            }
            $balance->decrement('allocated_quantity', $line->quantity);
            $this->allocationEvent('return_identity_original_correction', $case, $inventory, $actor,
                $line->account_id, $line->quantity);
        }
    }

    private function isIsolated(CustomerReturnExceptionCase $case, int $returnQuantity, int $quantity, ?ProductInventory $inventory, ?StockMovement $posting): bool
    {
        return $case->original_qc_result === 'pass' && $quantity === $returnQuantity && $inventory !== null
            && $posting !== null && $posting->available_before === 0 && $posting->available_after === $quantity
            && $inventory->available_quantity === $quantity && $inventory->reserved_quantity === 0
            && ! StockMovement::query()->where('product_inventory_id', $inventory->id)->where('id', '>', $posting->id)->exists();
    }

    private function movement(ProductInventory $inventory, InventoryReservation $reservation, User $actor,
        string $reference, StockMovementType $type, int $quantity, int $availableDelta, int $reservedDelta,
        int $reservedBefore, string $reason): void
    {
        StockMovement::query()->create([
            'reference' => $reference, 'movement_group' => (string) Str::uuid(),
            'product_inventory_id' => $inventory->id, 'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id, 'movement_type' => $type,
            'quantity' => $quantity, 'available_delta' => $availableDelta,
            'reserved_delta' => $reservedDelta, 'damaged_delta' => 0,
            'available_before' => $inventory->available_quantity, 'available_after' => $inventory->available_quantity,
            'reserved_before' => $reservedBefore, 'reserved_after' => $inventory->reserved_quantity,
            'damaged_before' => $inventory->damaged_quantity, 'damaged_after' => $inventory->damaged_quantity,
            'average_cost_before' => $inventory->average_cost, 'average_cost_after' => $inventory->average_cost,
            'source_type' => $reservation->getMorphClass(), 'source_id' => $reservation->id,
            'reason' => $reason, 'actor_user_id' => $actor->id, 'occurred_at' => now(),
        ]);
    }

    private function allocationEvent(string $action, CustomerReturnExceptionCase $case, ProductInventory $inventory,
        User $actor, int $accountId, int $quantity): void
    {
        InventoryAllocationEvent::query()->create([
            'event_key' => (string) Str::uuid(), 'event_type' => $action,
            'product_inventory_id' => $inventory->id, 'from_account_id' => $accountId,
            'quantity' => $quantity, 'source_type' => $case->getMorphClass(), 'source_id' => $case->id,
            'performed_by_user_id' => $actor->id, 'reason' => 'Customer Return evidence exception protection',
            'created_at' => now(),
        ]);
    }

    private function event(CustomerReturnExceptionCase $case, User $actor, string $action, array $details): void
    {
        $case->events()->create(['actor_user_id' => $actor->id, 'action' => $action,
            'details' => $details, 'created_at' => now()]);
    }
}
