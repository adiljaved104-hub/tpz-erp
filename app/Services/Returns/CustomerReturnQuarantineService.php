<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockMovementBucket;
use App\Enums\StockMovementType;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnFinding;
use App\Models\CustomerReturnInspection;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnQuarantine;
use App\Models\CustomerReturnStatusEvent;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\StockMovementBucketChange;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerReturnQuarantineService
{
    private const PROBLEMS = ['wrong_item', 'serial_mismatch', 'empty_package'];

    private const DISPOSITIONS = ['retain_in_quarantine', 'returned_to_sender', 'empty_package_confirmed'];

    public function __construct(
        private readonly CustomerReturnAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    /** Record a discrepancy before company receipt, without posting the expected SKU. */
    public function flagBeforeReceipt(CustomerReturnItem $item, array $input, User $actor, ?string $claimCaseReference = null): CustomerReturnQuarantine
    {
        $validated = Validator::make($input, [
            'problem_type' => ['required', 'in:'.implode(',', self::PROBLEMS)],
            'actual_custody_quantity' => ['required', 'integer', 'min:0', 'max:10000'],
            'actual_product_name' => ['nullable', 'string', 'max:255'],
            'actual_serial_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $item->loadMissing('customerReturn.platform');
        $claimEligible = $item->customerReturn->marketplace_platform_id !== null
            && $item->customerReturn->platform?->customer_return_claims_enabled === true;
        if ($claimEligible && $claimCaseReference === null) {
            throw new CustomerReturnException('Reserve the eligible exception case reference before starting the Return transaction.');
        }

        return DB::transaction(function () use ($item, $validated, $actor, $claimEligible, $claimCaseReference): CustomerReturnQuarantine {
            $locked = CustomerReturnItem::query()->lockForUpdate()->findOrFail($item->id);
            $return = CustomerReturn::query()->with('fulfillmentWarehouse')->lockForUpdate()->findOrFail($locked->customer_return_id);
            $this->authorization->authorize($actor, CustomerReturnPermission::Receive, $return);
            if ($return->status !== CustomerReturnStatus::Draft || $return->receiving_warehouse_id === null
                || $return->fulfillmentWarehouse->location_type === InventoryLocationType::MarketplaceFulfilment) {
                throw new CustomerReturnException('This item cannot be quarantined through company receipt. Use the marketplace return workflow where applicable.');
            }
            if ($existing = $locked->quarantine()->first()) {
                return $existing;
            }
            if ($validated['problem_type'] === 'empty_package' && $validated['actual_custody_quantity'] !== 0) {
                throw new CustomerReturnException('An empty package cannot contain a received item.');
            }
            if ($validated['problem_type'] !== 'empty_package' && $validated['actual_custody_quantity'] < 1) {
                throw new CustomerReturnException('Enter the number of actual items held in quarantine.');
            }
            $finding = CustomerReturnFinding::query()->create([
                'customer_return_item_id' => $locked->id, 'recorded_by_user_id' => $actor->id,
                'request_id' => (string) Str::uuid(), 'problem_type' => $validated['problem_type'],
                'actual_product_name' => $validated['actual_product_name'] ?? null,
                'actual_serial_number' => $validated['actual_serial_number'] ?? null,
                'remarks' => $validated['remarks'] ?? null, 'recorded_at' => now(),
            ]);
            $quarantine = CustomerReturnQuarantine::query()->create([
                'customer_return_item_id' => $locked->id, 'finding_id' => $finding->id,
                'custody_warehouse_id' => $return->receiving_warehouse_id,
                'expected_quantity' => $locked->return_quantity,
                'actual_custody_quantity' => $validated['actual_custody_quantity'],
                'problem_type' => $validated['problem_type'],
                'actual_product_name' => $validated['actual_product_name'] ?? null,
                'actual_serial_number' => $validated['actual_serial_number'] ?? null,
                'remarks' => $validated['remarks'] ?? null, 'recorded_by_user_id' => $actor->id,
                'claim_case_reference' => $claimEligible ? $claimCaseReference : null,
            ]);
            $this->activity->log('customer_return.quarantined', $actor, $return, [
                'return_item_id' => $locked->id, 'expected_quantity' => $locked->return_quantity,
                'actual_custody_quantity' => $quarantine->actual_custody_quantity,
                'claim_case_reference' => $quarantine->claim_case_reference,
            ]);

            return $quarantine;
        }, 5);
    }

    /** Owner/Admin records an explicit custody decision; no sellable/damaged stock is created. */
    public function review(CustomerReturnItem $item, array $input, User $actor): CustomerReturnQuarantine
    {
        $validated = Validator::make($input, [
            'disposition' => ['required', 'in:'.implode(',', self::DISPOSITIONS)],
            'actual_custody_quantity' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'review_notes' => ['required', 'string', 'max:5000'],
            'review_key' => ['required', 'uuid'],
        ])->validate();
        if (! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            throw new AuthorizationException;
        }
        $return = $item->customerReturn()->with('fulfillmentWarehouse')->firstOrFail();
        $this->authorization->authorize($actor, CustomerReturnPermission::Inspect, $return);
        if ($return->fulfillmentWarehouse->location_type === InventoryLocationType::MarketplaceFulfilment) {
            throw new CustomerReturnException('Marketplace wrong-item custody remains on hold until an approved inventory and ledger resolution rule is available.');
        }
        $previous = $item->quarantine()->where('review_key', $validated['review_key'])->first();
        if ($previous?->status === 'resolved') {
            if ($previous->disposition !== $validated['disposition']
                || (isset($validated['actual_custody_quantity']) && $previous->actual_custody_quantity !== $validated['actual_custody_quantity'])
                || $previous->review_notes !== $validated['review_notes']) {
                throw new CustomerReturnException('This review ID was already used for a different decision.');
            }

            return $previous;
        }
        if ($return->status !== CustomerReturnStatus::QcPending || $return->receiving_warehouse_id === null) {
            throw new CustomerReturnException('Only a physically received company Return can be reviewed here.');
        }
        // A reversal, if required, is an auditable correction of the provisional
        // original-SKU receipt, never a write-off or receipt of the unrelated item.
        $correctionReference = $this->references->nextStockMovementReference();

        return DB::transaction(function () use ($item, $validated, $actor, $correctionReference): CustomerReturnQuarantine {
            $locked = CustomerReturnItem::query()->lockForUpdate()->findOrFail($item->id);
            $return = CustomerReturn::query()->with('items')->lockForUpdate()->findOrFail($locked->customer_return_id);
            $this->authorization->authorize($actor, CustomerReturnPermission::Inspect, $return);
            $quarantine = $locked->quarantine()->lockForUpdate()->first();
            if ($quarantine?->status === 'resolved') {
                if ($quarantine->review_key === $validated['review_key']) {
                    return $quarantine;
                }
                throw new CustomerReturnException('This exception has already been reviewed.');
            }
            if ($return->status !== CustomerReturnStatus::QcPending || $return->receiving_warehouse_id === null
                || $locked->inspections()->exists() || $locked->marketplaceDispositions()->exists()
                || $locked->removalItems()->exists()) {
                throw new CustomerReturnException('This exception needs separate inventory review because stock has already been classified or moved.');
            }
            $finding = $locked->findings()->whereIn('problem_type', self::PROBLEMS)->first();
            if ($finding === null) {
                throw new CustomerReturnException('Record wrong-item, serial-mismatch or empty-package evidence before review.');
            }
            if (($finding->problem_type === 'empty_package') !== ($validated['disposition'] === 'empty_package_confirmed')) {
                throw new CustomerReturnException('The disposition must match the recorded empty-package finding.');
            }
            if ($quarantine === null) {
                $quarantine = CustomerReturnQuarantine::query()->create([
                    'customer_return_item_id' => $locked->id, 'finding_id' => $finding->id,
                    'custody_warehouse_id' => $return->receiving_warehouse_id,
                    'expected_quantity' => $locked->return_quantity,
                    'problem_type' => $finding->problem_type,
                    'actual_product_name' => $finding->actual_product_name,
                    'actual_serial_number' => $finding->actual_serial_number,
                    'remarks' => $finding->remarks, 'recorded_by_user_id' => $finding->recorded_by_user_id,
                    'actual_custody_quantity' => $validated['actual_custody_quantity'] ?? null,
                ]);
            }
            // The receiving employee already recorded the physical count. Owner/Admin
            // approves its disposition here, rather than performing QC a second time.
            if ($quarantine->actual_custody_quantity === null) {
                throw new CustomerReturnException('Record the actual items received before resolving this exception.');
            }
            if (isset($validated['actual_custody_quantity'])
                && $validated['actual_custody_quantity'] !== $quarantine->actual_custody_quantity) {
                throw new CustomerReturnException('The recorded physical count cannot be changed during disposition review.');
            }
            $validated['actual_custody_quantity'] = $quarantine->actual_custody_quantity;
            if ($finding->problem_type === 'empty_package' && $validated['actual_custody_quantity'] !== 0) {
                throw new CustomerReturnException('An empty package cannot contain a received item.');
            }
            if ($finding->problem_type !== 'empty_package' && $validated['actual_custody_quantity'] < 1) {
                throw new CustomerReturnException('Enter the actual quantity held or returned to sender.');
            }
            $previousCustodyQuantity = $quarantine->actual_custody_quantity;
            $receipt = StockMovement::query()->where('source_type', $locked->getMorphClass())
                ->where('source_id', $locked->id)
                ->where('movement_type', StockMovementType::CustomerReturnCompanyReceipt)->first();
            if ($receipt !== null) {
                $inventory = ProductInventory::query()->where('product_id', $locked->product_id)
                    ->where('warehouse_id', $return->receiving_warehouse_id)->lockForUpdate()->firstOrFail();
                $quantity = $locked->return_quantity;
                $value = bcmul((string) $locked->inventory_unit_cost, (string) $quantity, 4);
                if ($inventory->qc_pending_quantity < $quantity || bccomp((string) $inventory->qc_pending_value, $value, 4) < 0) {
                    throw new CustomerReturnException('The original-product QC Pending balance cannot be corrected safely.');
                }
                $beforeQuantity = $inventory->qc_pending_quantity;
                $beforeValue = (string) $inventory->qc_pending_value;
                $inventory->forceFill([
                    'qc_pending_quantity' => $beforeQuantity - $quantity,
                    'qc_pending_value' => bcsub($beforeValue, $value, 4),
                ])->save();
                $movement = StockMovement::query()->create([
                    'reference' => $correctionReference, 'movement_group' => (string) Str::uuid(),
                    'product_inventory_id' => $inventory->id, 'product_id' => $inventory->product_id,
                    'warehouse_id' => $inventory->warehouse_id,
                    'movement_type' => StockMovementType::CustomerReturnReceiptCorrection,
                    'quantity' => $quantity, 'available_delta' => 0, 'reserved_delta' => 0,
                    'damaged_delta' => 0, 'available_before' => $inventory->available_quantity,
                    'available_after' => $inventory->available_quantity,
                    'reserved_before' => $inventory->reserved_quantity,
                    'reserved_after' => $inventory->reserved_quantity,
                    'damaged_before' => $inventory->damaged_quantity,
                    'damaged_after' => $inventory->damaged_quantity,
                    'unit_cost' => $locked->inventory_unit_cost, 'average_cost_before' => $inventory->average_cost,
                    'average_cost_after' => $inventory->average_cost, 'source_type' => $quarantine->getMorphClass(),
                    'source_id' => $quarantine->id, 'reason' => 'Correction: expected return item was not recovered',
                    'actor_user_id' => $actor->id, 'occurred_at' => now(),
                ]);
                StockMovementBucketChange::query()->create([
                    'stock_movement_id' => $movement->id, 'bucket' => StockMovementBucket::QcPending,
                    'quantity_delta' => -$quantity, 'quantity_before' => $beforeQuantity,
                    'quantity_after' => $inventory->qc_pending_quantity,
                    'value_delta' => bcsub('0', $value, 4), 'value_before' => $beforeValue,
                    'value_after' => (string) $inventory->qc_pending_value,
                ]);
            }
            $quarantine->forceFill([
                'actual_custody_quantity' => $validated['actual_custody_quantity'],
                'status' => 'resolved', 'disposition' => $validated['disposition'],
                'review_notes' => $validated['review_notes'], 'review_key' => $validated['review_key'],
                'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
            ])->save();
            $this->activity->log('customer_return.quarantine_reviewed', $actor, $return, [
                'return_item_id' => $locked->id, 'disposition' => $quarantine->disposition,
                'previous_custody_quantity' => $previousCustodyQuantity,
                'confirmed_custody_quantity' => $quarantine->actual_custody_quantity,
                'expected_product_receipt_corrected' => $receipt !== null,
            ]);
            $complete = $return->items->every(function (CustomerReturnItem $returnItem): bool {
                $inspected = (int) CustomerReturnInspection::query()->where('customer_return_item_id', $returnItem->id)->sum('quantity');
                $resolved = (int) $returnItem->quarantine()->where('status', 'resolved')->sum('expected_quantity');

                return $inspected + $resolved === $returnItem->return_quantity;
            });
            if ($complete) {
                $return->forceFill(['status' => CustomerReturnStatus::Completed, 'completed_at' => now()])->save();
                CustomerReturnStatusEvent::query()->create([
                    'customer_return_id' => $return->id, 'from_status' => CustomerReturnStatus::QcPending,
                    'to_status' => CustomerReturnStatus::Completed, 'actor_user_id' => $actor->id,
                    'reason' => 'Identity exception reviewed; no original-product stock created', 'created_at' => now(),
                ]);
            }

            return $quarantine->refresh();
        }, 5);
    }
}
