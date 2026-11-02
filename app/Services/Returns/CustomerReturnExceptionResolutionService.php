<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnPermission;
use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\EmployeeRole;
use App\Enums\StockMovementType;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturnExceptionCase;
use App\Models\CustomerReturnQuarantine;
use App\Models\DamagedStockEvent;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Claims\SafetClaimService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerReturnExceptionResolutionService
{
    public function __construct(
        private readonly CustomerReturnAuthorization $authorization,
        private readonly CustomerReturnExceptionHoldService $holds,
        private readonly InventoryAllocationService $allocations,
        private readonly SafetClaimService $claims,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    /** Owner/Admin may reject a false conflict, or correct a safely held original item. */
    public function resolve(CustomerReturnExceptionCase $case, array $input, User $actor): CustomerReturnExceptionCase
    {
        $data = Validator::make($input, [
            'decision' => ['required', 'in:original_qc_correct,damage_confirmed,identity_confirmed'],
            'reason' => ['required', 'string', 'max:5000'],
            'resolution_key' => ['required', 'uuid'],
            'disposition' => ['required_if:decision,identity_confirmed', 'nullable', 'in:retain_in_quarantine,returned_to_sender,empty_package_confirmed'],
            'actual_custody_quantity' => ['required_if:decision,identity_confirmed', 'nullable', 'integer', 'min:0', 'max:10000'],
        ])->validate();
        if (! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            throw new AuthorizationException;
        }
        $case->loadMissing('item.customerReturn');
        $this->authorization->authorize($actor, CustomerReturnPermission::Inspect, $case->item->customerReturn);
        if ($case->resolved_at !== null) {
            return $this->sameDecisionOrFail($case, $data);
        }
        if ($case->kind !== 'qc_conflict' && ! ($case->kind === 'identity' && $case->original_qc_result !== null)) {
            throw new CustomerReturnException('Use the existing physical custody review for a wrong or different item.');
        }
        if ($case->kind === 'identity' && $data['decision'] === 'damage_confirmed') {
            throw new CustomerReturnException('A wrong or different item cannot be posted as damaged original-SKU stock.');
        }
        if ($case->kind !== 'identity' && $data['decision'] === 'identity_confirmed') {
            throw new CustomerReturnException('This evidence does not identify a different received item.');
        }
        if (in_array($data['decision'], ['damage_confirmed', 'identity_confirmed'], true) && $case->protection_status !== 'held') {
            throw new CustomerReturnException('The disputed unit is not safely isolated. Keep this case open for manual stock reconciliation.');
        }
        if ($data['decision'] === 'identity_confirmed') {
            if ($case->item->customerReturn->marketplace_platform_id !== null || $case->original_qc_result !== 'pass') {
                throw new CustomerReturnException('This identity discrepancy needs a separately approved inventory and ledger rule.');
            }
            $this->assertCustodyDecision($case, $data);
        }

        // Never request a reference from inside the business transaction.
        $releaseReference = $case->inventory_reservation_id ? $this->references->nextStockMovementReference() : null;
        $damageReference = $data['decision'] === 'damage_confirmed' ? $this->references->nextDamagedStockReference() : null;
        $movementReference = in_array($data['decision'], ['damage_confirmed', 'identity_confirmed'], true)
            ? $this->references->nextStockMovementReference() : null;
        $claimReference = $data['decision'] === 'damage_confirmed'
            && $this->claims->eligible($case->item, $case->new_problem_type)
                ? $this->references->nextSafetClaimReference() : null;

        return DB::transaction(function () use ($case, $data, $actor, $releaseReference, $damageReference, $movementReference, $claimReference): CustomerReturnExceptionCase {
            $locked = CustomerReturnExceptionCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->resolved_at !== null) {
                return $this->sameDecisionOrFail($locked, $data);
            }
            $item = $locked->item;
            $return = $item->customerReturn;
            $this->authorization->authorize($actor, CustomerReturnPermission::Inspect, $return);
            if (in_array($data['decision'], ['damage_confirmed', 'identity_confirmed'], true)
                && $locked->protection_status !== 'held') {
                throw new CustomerReturnException('The disputed unit is not safely isolated.');
            }
            if ($data['decision'] === 'identity_confirmed'
                && ($return->marketplace_platform_id !== null || $locked->original_qc_result !== 'pass')) {
                throw new CustomerReturnException('This identity discrepancy needs a separately approved inventory and ledger rule.');
            }

            $inventory = null;
            if ($locked->inventory_reservation_id !== null) {
                $reservation = $locked->reservation;
                $holdMovement = StockMovement::query()->where('source_type', $reservation->getMorphClass())
                    ->where('source_id', $reservation->id)->where('movement_type', StockMovementType::Reservation)->firstOrFail();
                if (in_array($data['decision'], ['damage_confirmed', 'identity_confirmed'], true)
                    && StockMovement::query()->where('product_inventory_id', $reservation->product_inventory_id)
                        ->where('id', '>', $holdMovement->id)->exists()) {
                    throw new CustomerReturnException('Other stock has moved since the hold. Keep this case open for manual reconciliation.');
                }
                $inventory = $this->holds->release($locked, $actor, $releaseReference);
            }
            $damage = null;
            if ($data['decision'] === 'damage_confirmed') {
                $quantity = $locked->affected_quantity;
                if ($inventory === null || $inventory->sellableQuantity() !== $quantity) {
                    throw new CustomerReturnException('The isolated quantity no longer matches the available stock.');
                }
                $before = $inventory->available_quantity;
                $damagedBefore = $inventory->damaged_quantity;
                $inventory->forceFill(['available_quantity' => $before - $quantity,
                    'damaged_quantity' => $damagedBefore + $quantity])->save();
                $movement = StockMovement::query()->create([
                    'reference' => $movementReference, 'movement_group' => (string) Str::uuid(),
                    'idempotency_key' => $data['resolution_key'],
                    'product_inventory_id' => $inventory->id, 'product_id' => $inventory->product_id,
                    'warehouse_id' => $inventory->warehouse_id, 'movement_type' => StockMovementType::MarkDamaged,
                    'quantity' => $quantity, 'available_delta' => -$quantity, 'reserved_delta' => 0,
                    'damaged_delta' => $quantity, 'available_before' => $before,
                    'available_after' => $inventory->available_quantity,
                    'reserved_before' => $inventory->reserved_quantity, 'reserved_after' => $inventory->reserved_quantity,
                    'damaged_before' => $damagedBefore, 'damaged_after' => $inventory->damaged_quantity,
                    'unit_cost' => $item->inventory_unit_cost,
                    'average_cost_before' => $inventory->average_cost, 'average_cost_after' => $inventory->average_cost,
                    'source_type' => $locked->getMorphClass(), 'source_id' => $locked->id,
                    'reason' => 'Owner/Admin correction of disputed Customer Return QC: '.$data['reason'],
                    'actor_user_id' => $actor->id, 'occurred_at' => now(),
                ]);
                $this->allocations->markDamaged($inventory, $quantity, $movement, $actor);
                $damage = DamagedStockEvent::query()->create([
                    'reference' => $damageReference, 'product_inventory_id' => $inventory->id,
                    'product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id,
                    'quantity' => $quantity, 'source' => DamagedStockSource::CustomerReturn,
                    'source_type' => $locked->getMorphClass(), 'source_id' => $locked->id,
                    'marketplace_platform_id' => $return->marketplace_platform_id,
                    'customer_return_id' => $return->id, 'customer_return_item_id' => $item->id,
                    'order_id' => $return->order_id, 'reason' => 'Approved correction: new evidence confirmed damage',
                    'notes' => $data['reason'], 'occurred_at' => now(), 'reported_by_user_id' => $actor->id,
                    'status' => DamagedStockStatus::Damaged, 'idempotency_key' => $data['resolution_key'],
                ]);
                $this->claims->createFromQcDamage($damage, $item, $actor, $claimReference, $locked->new_problem_type);
            }
            if ($data['decision'] === 'identity_confirmed') {
                $quantity = $locked->affected_quantity;
                if ($inventory === null || $inventory->sellableQuantity() !== $quantity
                    || $item->quarantine()->exists()) {
                    throw new CustomerReturnException('The original-SKU correction cannot be isolated safely.');
                }
                $this->holds->removeMisclassifiedOriginal($locked, $inventory, $actor);
                $before = $inventory->available_quantity;
                $inventory->forceFill(['available_quantity' => $before - $quantity])->save();
                StockMovement::query()->create([
                    'reference' => $movementReference, 'movement_group' => (string) Str::uuid(),
                    'idempotency_key' => $data['resolution_key'],
                    'product_inventory_id' => $inventory->id, 'product_id' => $inventory->product_id,
                    'warehouse_id' => $inventory->warehouse_id,
                    'movement_type' => StockMovementType::CustomerReturnReceiptCorrection,
                    'quantity' => $quantity, 'available_delta' => -$quantity, 'reserved_delta' => 0,
                    'damaged_delta' => 0, 'available_before' => $before,
                    'available_after' => $inventory->available_quantity,
                    'reserved_before' => $inventory->reserved_quantity, 'reserved_after' => $inventory->reserved_quantity,
                    'damaged_before' => $inventory->damaged_quantity, 'damaged_after' => $inventory->damaged_quantity,
                    'unit_cost' => $item->inventory_unit_cost,
                    'average_cost_before' => $inventory->average_cost, 'average_cost_after' => $inventory->average_cost,
                    'source_type' => $locked->getMorphClass(), 'source_id' => $locked->id,
                    'reason' => 'Owner/Admin correction: originally ordered unit was not recovered. '.$data['reason'],
                    'actor_user_id' => $actor->id, 'occurred_at' => now(),
                ]);
                $finding = $locked->finding;
                CustomerReturnQuarantine::query()->create([
                    'customer_return_item_id' => $item->id, 'finding_id' => $finding->id,
                    'custody_warehouse_id' => $return->receiving_warehouse_id,
                    'expected_quantity' => $item->return_quantity,
                    'actual_custody_quantity' => $data['actual_custody_quantity'],
                    'problem_type' => $finding->problem_type,
                    'actual_product_name' => $finding->actual_product_name,
                    'actual_serial_number' => $finding->actual_serial_number,
                    'remarks' => $finding->remarks,
                    'recorded_by_user_id' => $finding->recorded_by_user_id,
                    'status' => 'resolved', 'disposition' => $data['disposition'],
                    'review_notes' => $data['reason'], 'review_key' => $data['resolution_key'],
                    'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                ]);
            }
            $locked->forceFill([
                'status' => 'resolved', 'open_key' => null, 'protection_status' => 'released',
                'damaged_stock_event_id' => $damage?->id,
                'resolution' => $data['decision'], 'resolution_reason' => $data['reason'],
                'resolution_key' => $data['resolution_key'], 'reviewed_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ])->save();
            $locked->events()->create(['actor_user_id' => $actor->id, 'action' => 'resolved',
                'details' => ['decision' => $data['decision'], 'reason' => $data['reason'],
                    'damage_event_id' => $damage?->id,
                    'disposition' => $data['disposition'] ?? null,
                    'custody_quantity' => $data['actual_custody_quantity'] ?? null], 'created_at' => now()]);
            $this->activity->log('customer_return.exception_resolved', $actor, $return, [
                'case_id' => $locked->id, 'decision' => $data['decision'], 'damage_event_id' => $damage?->id,
            ]);

            return $locked->refresh();
        }, 5);
    }

    private function sameDecisionOrFail(CustomerReturnExceptionCase $case, array $data): CustomerReturnExceptionCase
    {
        if ($case->resolution_key !== $data['resolution_key'] || $case->resolution !== $data['decision']
            || $case->resolution_reason !== $data['reason']) {
            throw new CustomerReturnException('This case has already been resolved with a different decision.');
        }
        if ($case->resolution === 'identity_confirmed') {
            $quarantine = $case->item->quarantine()->firstOrFail();
            if ($quarantine->disposition !== ($data['disposition'] ?? null)
                || $quarantine->actual_custody_quantity !== ($data['actual_custody_quantity'] ?? null)) {
                throw new CustomerReturnException('This review ID was already used with a different custody decision.');
            }
        }

        return $case;
    }

    private function assertCustodyDecision(CustomerReturnExceptionCase $case, array $data): void
    {
        $empty = $case->new_problem_type === 'empty_package';
        if ($empty !== ($data['disposition'] === 'empty_package_confirmed')
            || ($empty && $data['actual_custody_quantity'] !== 0)
            || (! $empty && $data['actual_custody_quantity'] < 1)) {
            throw new CustomerReturnException('The approved custody decision must match the physical item count and reported discrepancy.');
        }
    }
}
