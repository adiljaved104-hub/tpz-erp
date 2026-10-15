<?php

namespace App\Services\Qc;

use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\StockMovementBucket;
use App\Enums\StockMovementType;
use App\Models\ProductInventory;
use App\Models\QcRecordedStockIntake;
use App\Models\QcRecordedStockIntakeAdjustment;
use App\Models\StockMovement;
use App\Models\StockMovementBucketChange;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QcRecordedStockIntakeAdjustmentService
{
    public function __construct(
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function canAdjust(?User $actor): bool
    {
        return $actor?->employee?->status === true
            && in_array($actor->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    /** @param array{adjustment_quantity:int|string,reason:string,idempotency_key:string} $data */
    public function adjust(int $intakeId, User $actor, array $data): QcRecordedStockIntakeAdjustment
    {
        $this->authorize($actor);
        $validated = Validator::make($data, [
            'adjustment_quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $validated['adjustment_quantity'] = (int) $validated['adjustment_quantity'];
        $validated['reason'] = trim($validated['reason']);

        if ($validated['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        if ($existing = QcRecordedStockIntakeAdjustment::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $this->returnIdempotentResult($existing, $intakeId, $actor, $validated);
        }

        // Movement references are reserved before the transaction in production.
        $movementReference = $this->references->nextStockMovementReference();

        try {
            return DB::transaction(function () use ($intakeId, $actor, $validated, $movementReference): QcRecordedStockIntakeAdjustment {
                $intake = QcRecordedStockIntake::query()->lockForUpdate()->with(['product:id,condition', 'receiptItem:id,accepted_quantity,inventory_unit_cost'])->findOrFail($intakeId);

                if ($existing = QcRecordedStockIntakeAdjustment::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
                    return $this->returnIdempotentResult($existing, $intakeId, $actor, $validated);
                }

                if ($intake->product->condition !== ProductCondition::Renewed) {
                    throw ValidationException::withMessages(['recorded_stock_intake_id' => 'Only Recorded Renewed QC intake can be adjusted here.']);
                }

                $quantityBefore = (int) $intake->accepted_quantity;
                $quantityAfter = $quantityBefore - $validated['adjustment_quantity'];
                $started = (int) $intake->started_quantity;
                $released = (int) $intake->released_quantity;
                if ($quantityAfter < 0 || $quantityAfter < $started || $quantityAfter < $released) {
                    throw ValidationException::withMessages([
                        'adjustment_quantity' => 'The reduction cannot leave fewer recorded units than QC jobs already started or units already released.',
                    ]);
                }

                $inventory = ProductInventory::query()
                    ->where('product_id', $intake->product_id)
                    ->where('warehouse_id', $intake->warehouse_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $pendingBefore = (int) $inventory->qc_pending_quantity;
                $pendingValueBefore = (string) $inventory->qc_pending_value;
                $unitCost = (string) $intake->receiptItem->inventory_unit_cost;
                $valueReduction = bcmul($unitCost, (string) $validated['adjustment_quantity'], 4);
                if ($pendingBefore < $validated['adjustment_quantity'] || bccomp($pendingValueBefore, $valueReduction, 4) < 0) {
                    throw ValidationException::withMessages([
                        'adjustment_quantity' => 'QC Pending inventory is insufficient for this intake reduction.',
                    ]);
                }

                $pendingAfter = $pendingBefore - $validated['adjustment_quantity'];
                $pendingValueAfter = bcsub($pendingValueBefore, $valueReduction, 4);
                if ($pendingValueAfter[0] === '-' || bccomp($pendingValueAfter, '0', 4) < 0
                    || ($pendingAfter === 0 && bccomp($pendingValueAfter, '0', 4) !== 0)) {
                    throw ValidationException::withMessages([
                        'adjustment_quantity' => 'This reduction would make QC Pending quantity or value inconsistent.',
                    ]);
                }

                $averageCost = $inventory->average_cost === null ? null : (string) $inventory->average_cost;
                $inventory->forceFill([
                    'qc_pending_quantity' => $pendingAfter,
                    'qc_pending_value' => $pendingValueAfter,
                ])->save();
                $intake->forceFill(['accepted_quantity' => $quantityAfter])->save();

                $movement = StockMovement::query()->create([
                    'reference' => $movementReference,
                    'movement_group' => (string) Str::uuid(),
                    'idempotency_key' => $validated['idempotency_key'],
                    'product_inventory_id' => $inventory->id,
                    'product_id' => $inventory->product_id,
                    'warehouse_id' => $inventory->warehouse_id,
                    'movement_type' => StockMovementType::RenewedQcIntakeAdjustment,
                    'quantity' => $validated['adjustment_quantity'],
                    'available_delta' => 0,
                    'reserved_delta' => 0,
                    'damaged_delta' => 0,
                    'available_before' => $inventory->available_quantity,
                    'available_after' => $inventory->available_quantity,
                    'reserved_before' => $inventory->reserved_quantity,
                    'reserved_after' => $inventory->reserved_quantity,
                    'damaged_before' => $inventory->damaged_quantity,
                    'damaged_after' => $inventory->damaged_quantity,
                    'unit_cost' => $unitCost,
                    'average_cost_before' => $averageCost,
                    'average_cost_after' => $averageCost,
                    'source_type' => $intake->getMorphClass(),
                    'source_id' => $intake->id,
                    'reason' => $validated['reason'],
                    'actor_user_id' => $actor->id,
                    'occurred_at' => now(),
                ]);
                StockMovementBucketChange::query()->create([
                    'stock_movement_id' => $movement->id,
                    'bucket' => StockMovementBucket::QcPending,
                    'quantity_delta' => -$validated['adjustment_quantity'],
                    'quantity_before' => $pendingBefore,
                    'quantity_after' => $pendingAfter,
                    'value_delta' => bcsub('0', $valueReduction, 4),
                    'value_before' => $pendingValueBefore,
                    'value_after' => $pendingValueAfter,
                ]);

                $adjustment = QcRecordedStockIntakeAdjustment::query()->create([
                    'recorded_stock_intake_id' => $intake->id,
                    'quantity_before' => $quantityBefore,
                    'adjustment_quantity' => $validated['adjustment_quantity'],
                    'quantity_after' => $quantityAfter,
                    'unit_cost' => $unitCost,
                    'value_reduction' => $valueReduction,
                    'reason' => $validated['reason'],
                    'performed_by_user_id' => $actor->id,
                    'idempotency_key' => $validated['idempotency_key'],
                    'stock_movement_id' => $movement->id,
                    'created_at' => now(),
                ]);

                $this->activity->log('qc.recorded_stock_intake_adjusted', $actor, $adjustment, [
                    'intake_id' => $intake->id,
                    'quantity_before' => $quantityBefore,
                    'adjustment_quantity' => $validated['adjustment_quantity'],
                    'quantity_after' => $quantityAfter,
                    'movement_reference' => $movement->reference,
                ]);

                return $adjustment;
            }, 5);
        } catch (QueryException $exception) {
            // A concurrent retry may win the unique idempotency key. Only then is it safe to replay.
            $existing = QcRecordedStockIntakeAdjustment::query()->where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing !== null) {
                return $this->returnIdempotentResult($existing, $intakeId, $actor, $validated);
            }

            throw $exception;
        }
    }

    private function returnIdempotentResult(QcRecordedStockIntakeAdjustment $existing, int $intakeId, User $actor, array $validated): QcRecordedStockIntakeAdjustment
    {
        if ($existing->recorded_stock_intake_id !== $intakeId
            || $existing->performed_by_user_id !== $actor->id
            || $existing->adjustment_quantity !== $validated['adjustment_quantity']
            || $existing->reason !== $validated['reason']) {
            throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for a different intake adjustment.']);
        }

        return $existing->loadMissing('movement');
    }

    private function authorize(User $actor): void
    {
        throw_unless($this->canAdjust($actor), AuthorizationException::class);
    }
}
