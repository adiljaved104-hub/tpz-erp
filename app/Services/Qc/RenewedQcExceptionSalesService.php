<?php

namespace App\Services\Qc;

use App\Enums\InventoryReservationKind;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductCondition;
use App\Exceptions\InventoryInvariantException;
use App\Models\InventoryAllocationReservationLine;
use App\Models\InventoryReservation;
use App\Models\OrderItem;
use App\Models\QcRecordedStockBypassRelease;
use App\Models\QcRecordedStockBypassReservationAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Quantity-scoped sales provenance for Renewed units released without QC. */
class RenewedQcExceptionSalesService
{
    public function sync(InventoryReservation $reservation, OrderItem $item, User $actor): int
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('QC exception sales attribution requires an active transaction.');
        }

        $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);
        $item->loadMissing(['product', 'order']);
        if ($reservation->reservation_kind !== InventoryReservationKind::BaseProduct
            || $item->product?->condition !== ProductCondition::Renewed) {
            return 0;
        }
        if ($reservation->status !== InventoryReservationStatus::Active
            || $reservation->order_item_id !== $item->id
            || $reservation->product_id !== $item->product_id
            || $reservation->warehouse_id !== $item->order->warehouse_id
            || $reservation->quantity < 1) {
            throw new InventoryInvariantException('QC exception sales attribution does not match the active Order reservation.');
        }

        $capacities = InventoryAllocationReservationLine::query()
            ->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('account_id')
            ->map(fn (Collection $lines): int => (int) $lines->sum('quantity'));

        $releases = QcRecordedStockBypassRelease::query()
            ->with('intake:id,allocation_account_id')
            ->where('product_inventory_id', $reservation->product_inventory_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($releases->isEmpty()) {
            $this->releaseReserved($reservation, $actor);

            return 0;
        }

        $releaseIds = $releases->modelKeys();
        $allocations = QcRecordedStockBypassReservationAllocation::query()
            ->whereIn('bypass_release_id', $releaseIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($allocations->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')->groupBy('allocation_account_id') as $accountId => $rows) {
            $excess = max(0, (int) $rows->sum('quantity') - (int) ($capacities[$accountId] ?? 0));
            foreach ($rows->sortByDesc('id') as $row) {
                if ($excess === 0) {
                    break;
                }

                $release = min($excess, (int) $row->quantity);
                $retain = (int) $row->quantity - $release;
                $this->markReleased($row, $actor);
                if ($retain > 0) {
                    $this->createReserved($row->bypass_release_id, $reservation, $item, (int) $accountId, $retain, $actor);
                }
                $excess -= $release;
            }
        }

        $used = QcRecordedStockBypassReservationAllocation::query()
            ->whereIn('bypass_release_id', $releaseIds)
            ->whereIn('status', ['reserved', 'fulfilled'])
            ->selectRaw('bypass_release_id, SUM(quantity) AS used_quantity')
            ->groupBy('bypass_release_id')
            ->pluck('used_quantity', 'bypass_release_id')
            ->map(fn (mixed $quantity): int => (int) $quantity);
        $reservedByAccount = QcRecordedStockBypassReservationAllocation::query()
            ->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')
            ->selectRaw('allocation_account_id, SUM(quantity) AS reserved_quantity')
            ->groupBy('allocation_account_id')
            ->pluck('reserved_quantity', 'allocation_account_id')
            ->map(fn (mixed $quantity): int => (int) $quantity);

        foreach ($capacities as $accountId => $capacity) {
            $needed = max(0, (int) $capacity - (int) ($reservedByAccount[$accountId] ?? 0));
            if ($needed === 0) {
                continue;
            }

            foreach ($releases->filter(fn (QcRecordedStockBypassRelease $release): bool => (int) $release->intake?->allocation_account_id === (int) $accountId) as $release) {
                if ($needed === 0) {
                    break;
                }
                $available = max(0, (int) $release->quantity - (int) ($used[$release->id] ?? 0));
                $take = min($needed, $available);
                if ($take < 1) {
                    continue;
                }
                $this->createReserved($release->id, $reservation, $item, (int) $accountId, $take, $actor);
                $used[$release->id] = (int) ($used[$release->id] ?? 0) + $take;
                $reservedByAccount[$accountId] = (int) ($reservedByAccount[$accountId] ?? 0) + $take;
                $needed -= $take;
            }
        }

        $bypassQuantity = $this->reservedQuantityForReservation($reservation->id);
        $requiredQc = max(0, (int) $reservation->quantity - $bypassQuantity);
        if ($item->qcAssignments()->active()->count() > $requiredQc) {
            throw ValidationException::withMessages([
                'items' => 'Release excess QC device assignments before changing this Order Item quantity or stock source.',
            ]);
        }

        return $bypassQuantity;
    }

    public function release(InventoryReservation $reservation, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('QC exception reservation release requires an active transaction.');
        }

        $this->releaseReserved($reservation, $actor);
    }

    public function fulfill(InventoryReservation $reservation, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('QC exception fulfilment attribution requires an active transaction.');
        }

        QcRecordedStockBypassReservationAllocation::query()
            ->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (QcRecordedStockBypassReservationAllocation $allocation) use ($actor): void {
                $allocation->forceFill([
                    'status' => 'fulfilled',
                    'fulfilled_by_user_id' => $actor->id,
                    'fulfilled_at' => now(),
                ])->save();
            });
    }

    public function reservedQuantity(OrderItem $item): int
    {
        if ($item->relationLoaded('qcBypassReservationAllocations')) {
            return (int) $item->qcBypassReservationAllocations->where('status', 'reserved')->sum('quantity');
        }

        return (int) QcRecordedStockBypassReservationAllocation::query()
            ->where('order_item_id', $item->id)
            ->where('status', 'reserved')
            ->sum('quantity');
    }

    private function reservedQuantityForReservation(int $reservationId): int
    {
        return (int) QcRecordedStockBypassReservationAllocation::query()
            ->where('inventory_reservation_id', $reservationId)
            ->where('status', 'reserved')
            ->sum('quantity');
    }

    private function releaseReserved(InventoryReservation $reservation, User $actor): void
    {
        QcRecordedStockBypassReservationAllocation::query()
            ->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(fn (QcRecordedStockBypassReservationAllocation $allocation) => $this->markReleased($allocation, $actor));
    }

    private function markReleased(QcRecordedStockBypassReservationAllocation $allocation, User $actor): void
    {
        $allocation->forceFill([
            'status' => 'released',
            'released_by_user_id' => $actor->id,
            'released_at' => now(),
        ])->save();
    }

    private function createReserved(
        int $releaseId,
        InventoryReservation $reservation,
        OrderItem $item,
        int $accountId,
        int $quantity,
        User $actor,
    ): void {
        QcRecordedStockBypassReservationAllocation::query()->create([
            'bypass_release_id' => $releaseId,
            'inventory_reservation_id' => $reservation->id,
            'order_item_id' => $item->id,
            'allocation_account_id' => $accountId,
            'quantity' => $quantity,
            'status' => 'reserved',
            'reserved_by_user_id' => $actor->id,
            'reserved_at' => now(),
        ]);
    }
}
