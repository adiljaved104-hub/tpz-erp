<?php

namespace App\Services\Qc;

use App\Enums\InventoryPermission;
use App\Enums\ProductCondition;
use App\Enums\QcPermission;
use App\Enums\QcInspectionStatus;
use App\Enums\StockMovementBucket;
use App\Enums\StockMovementType;
use App\Models\ProductInventory;
use App\Models\QcRecordedStockBypassRelease;
use App\Models\QcRecordedStockIntake;
use App\Models\QcInspection;
use App\Models\StockMovement;
use App\Models\StockMovementBucketChange;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Owner-gated, time-limited intake-only exception for Renewed inventory entry.
 *
 * This does NOT create QC inspections/certificates or bypass Renewed sales
 * dispatch QC checks. Only unstarted GRN intake units are eligible.
 */
class RenewedStockQcExceptionService
{
    public function __construct(
        private readonly QcAuthorization $qcAuthorization,
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly WeightedAverageCostCalculator $costs,
        private readonly InventoryAllocationService $allocations,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function enabled(): bool
    {
        if (config('renewed_qc_exception.enabled') !== true) {
            return false;
        }

        $deadline = config('renewed_qc_exception.expires_at');
        if (! is_string($deadline) || trim($deadline) === '') {
            return false;
        }

        try {
            // Require an explicit timezone offset to prevent ambiguous expiry.
            if (! preg_match('/(?:Z|[+-]\\d{2}:\\d{2})$/', $deadline)) {
                return false;
            }

            return CarbonImmutable::now()->lessThanOrEqualTo(CarbonImmutable::parse($deadline));
        } catch (Throwable) {
            return false;
        }
    }

    public function canRelease(User $actor): bool
    {
        return $this->enabled()
            && $this->qcAuthorization->allows($actor, QcPermission::ReleaseWithoutQc);
    }

    /**
     * Read-only, responsibility-scoped queue for exception-only Renewed staff.
     * Does not grant QC Start or expose unrelated GRNs.
     */
    public function queueQuery(User $actor): Builder
    {
        $this->authorize($actor);
        $inventoryIds = ProductInventory::query()
            ->get(['id', 'product_id', 'warehouse_id'])
            ->filter(fn (ProductInventory $inventory): bool =>
                $this->inventoryAuthorization->allows($actor, InventoryPermission::View, $inventory))
            ->pluck('id')->all();

        return QcRecordedStockIntake::query()->awaitingStart()
            ->where('started_quantity', 0)
            ->where('released_quantity', 0)
            ->whereHas('product', fn (Builder $query) => $query->where('condition', ProductCondition::Renewed->value))
            ->whereExists(function ($query) use ($inventoryIds): void {
                $query->selectRaw('1')->from('product_inventories as eligible_inventory')
                    ->whereColumn('eligible_inventory.product_id', 'qc_recorded_stock_intakes.product_id')
                    ->whereColumn('eligible_inventory.warehouse_id', 'qc_recorded_stock_intakes.warehouse_id')
                    ->whereIn('eligible_inventory.id', $inventoryIds);
            })
            ->with(['product:id,sku,name,condition', 'warehouse:id,name', 'receiptItem.receipt:id,reference,received_at'])
            ->addSelect(['completed_quantity' => QcInspection::query()->selectRaw('COUNT(DISTINCT device_id)')
                ->whereColumn('recorded_stock_intake_id', 'qc_recorded_stock_intakes.id')
                ->where('status', QcInspectionStatus::Completed->value)]);
    }

    public function canReleaseIntake(QcRecordedStockIntake $intake, User $actor): bool
    {
        if (! $this->canRelease($actor)
            || (int) $intake->started_quantity !== 0
            || (int) $intake->released_quantity !== 0
            || (int) $intake->accepted_quantity < 1
            || $intake->product?->condition !== ProductCondition::Renewed) {
            return false;
        }

        $inventory = ProductInventory::query()
            ->where('product_id', $intake->product_id)
            ->where('warehouse_id', $intake->warehouse_id)
            ->first();

        return $inventory !== null
            && $this->inventoryAuthorization->allows($actor, InventoryPermission::View, $inventory);
    }

    public function release(int $intakeId, User $actor, array $input): QcRecordedStockBypassRelease
    {
        $this->authorize($actor);
        $data = Validator::make($input, [
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $data['reason'] = trim($data['reason']);

        if ($existing = QcRecordedStockBypassRelease::query()->where('idempotency_key', $data['idempotency_key'])->first()) {
            $this->assertReplayMatches($existing, $intakeId, $actor, $data);

            return $existing;
        }

        // Reference numbers intentionally advance outside the transaction.
        $movementReference = $this->references->nextStockMovementReference();

        return DB::transaction(function () use ($intakeId, $actor, $data, $movementReference): QcRecordedStockBypassRelease {
            $this->authorize($actor);
            $intake = QcRecordedStockIntake::query()
                ->with(['product', 'receiptItem', 'allocationAccount'])
                ->lockForUpdate()->findOrFail($intakeId);

            if ($intake->product?->condition !== ProductCondition::Renewed
                || (int) $intake->started_quantity !== 0
                || (int) $intake->released_quantity !== 0
                || $intake->accepted_quantity < $data['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => 'Only unstarted, unreleased Renewed GRN units may use this exception.',
                ]);
            }

            if ($intake->receiptItem === null
                || $intake->allocationAccount === null
                || $intake->allocationAccount->status !== true
                || $intake->product_id !== $intake->receiptItem->product_id) {
                throw ValidationException::withMessages(['quantity' => 'The original GRN or allocation holder is invalid.']);
            }

            $inventory = ProductInventory::query()
                ->where('product_id', $intake->product_id)
                ->where('warehouse_id', $intake->warehouse_id)
                ->lockForUpdate()->firstOrFail();

            // Employee responsibility scope remains mandatory.
            $this->inventoryAuthorization->authorize($actor, InventoryPermission::View, $inventory);

            $quantity = (int) $data['quantity'];
            $unitCost = (string) $intake->receiptItem->inventory_unit_cost;
            $value = bcmul($unitCost, (string) $quantity, 4);
            $pendingBefore = (int) $inventory->qc_pending_quantity;
            $pendingValueBefore = (string) $inventory->qc_pending_value;

            if ($pendingBefore < $quantity || bccomp($pendingValueBefore, $value, 4) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient recorded QC Pending stock or value.']);
            }

            $pendingAfter = $pendingBefore - $quantity;
            $pendingValueAfter = bcsub($pendingValueBefore, $value, 4);
            if (bccomp($pendingValueAfter, '0', 4) < 0
                || ($pendingAfter === 0 && bccomp($pendingValueAfter, '0', 4) !== 0)) {
                throw ValidationException::withMessages(['quantity' => 'QC Pending valuation would become inconsistent.']);
            }

            $qcBefore = (int) $intake->accepted_quantity;
            $availableBefore = (int) $inventory->available_quantity;
            $averageBefore = $inventory->average_cost;
            $averageAfter = $this->costs->weightedAverage(
                $inventory->totalOnHand(), $averageBefore, $quantity, $unitCost,
            );

            $release = QcRecordedStockBypassRelease::query()->create([
                'recorded_stock_intake_id' => $intake->id,
                'product_inventory_id' => $inventory->id,
                'quantity' => $quantity,
                'qc_quantity_before' => $qcBefore,
                'qc_quantity_after' => $qcBefore - $quantity,
                'unit_cost' => $unitCost,
                'released_value' => $value,
                'reason' => $data['reason'],
                'released_by_user_id' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'created_at' => now(),
            ]);

            $inventory->forceFill([
                'qc_pending_quantity' => $pendingAfter,
                'qc_pending_value' => $pendingValueAfter,
                'available_quantity' => $availableBefore + $quantity,
                'average_cost' => $averageAfter,
            ])->save();

            // Accepted GRN quantity stays immutable on receiptItem.
            // The recorded QC intake tracks only the portion still requiring QC,
            // as with existing manually adjusted QC intake quantities.
            $intake->forceFill(['accepted_quantity' => $qcBefore - $quantity])->save();

            $movement = StockMovement::query()->create([
                'reference' => $movementReference,
                'movement_group' => (string) Str::uuid(),
                'idempotency_key' => $data['idempotency_key'],
                'product_inventory_id' => $inventory->id,
                'product_id' => $inventory->product_id,
                'warehouse_id' => $inventory->warehouse_id,
                'movement_type' => StockMovementType::RenewedQcExceptionRelease,
                'quantity' => $quantity,
                'available_delta' => $quantity,
                'reserved_delta' => 0,
                'damaged_delta' => 0,
                'available_before' => $availableBefore,
                'available_after' => $availableBefore + $quantity,
                'reserved_before' => $inventory->reserved_quantity,
                'reserved_after' => $inventory->reserved_quantity,
                'damaged_before' => $inventory->damaged_quantity,
                'damaged_after' => $inventory->damaged_quantity,
                'unit_cost' => $unitCost,
                'average_cost_before' => $averageBefore,
                'average_cost_after' => $averageAfter,
                'source_type' => $release->getMorphClass(),
                'source_id' => $release->id,
                'reason' => 'Temporary Renewed QC exception: '.$data['reason'],
                'actor_user_id' => $actor->id,
                'occurred_at' => now(),
            ]);

            StockMovementBucketChange::query()->create([
                'stock_movement_id' => $movement->id,
                'bucket' => StockMovementBucket::QcPending,
                'quantity_delta' => -$quantity,
                'quantity_before' => $pendingBefore,
                'quantity_after' => $pendingAfter,
                'value_delta' => bcsub('0', $value, 4),
                'value_before' => $pendingValueBefore,
                'value_after' => $pendingValueAfter,
            ]);

            // A received Renewed unit has no sellable allocation until release.
            // Reuse the existing per-unit allocation release boundary.
            for ($i = 0; $i < $quantity; $i++) {
                $this->allocations->releaseRecordedQcReceiptUnit(
                    $intake->receiptItem,
                    $inventory,
                    (int) $intake->allocation_account_id,
                    (string) $intake->allocation_method,
                    $actor,
                    $release,
                );
            }

            DB::table('qc_recorded_stock_bypass_releases')->where('id', $release->id)
                ->update(['stock_movement_id' => $movement->id]);

            $this->activity->log('qc.renewed_stock_exception_released', $actor, $release, [
                'sku' => $intake->product->sku,
                'warehouse_id' => $intake->warehouse_id,
                'receipt_item_id' => $intake->purchase_receipt_item_id,
                'quantity' => $quantity,
                'qc_performed' => false,
            ]);

            return $release->refresh();
        }, 5);
    }

    private function authorize(User $actor): void
    {
        if (! $this->canRelease($actor)) {
            throw new AuthorizationException('Temporary Renewed QC exception is disabled, expired, or not permitted.');
        }
    }

    private function assertReplayMatches(QcRecordedStockBypassRelease $existing, int $intakeId, User $actor, array $data): void
    {
        if ($existing->recorded_stock_intake_id !== $intakeId
            || $existing->released_by_user_id !== $actor->id
            || $existing->quantity !== (int) $data['quantity']
            || $existing->reason !== $data['reason']) {
            throw ValidationException::withMessages(['idempotency_key' => 'This key was used for another exception release.']);
        }
    }
}
