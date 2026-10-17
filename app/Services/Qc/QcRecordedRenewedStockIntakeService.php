<?php

namespace App\Services\Qc;

use App\Enums\ProductCondition;
use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Enums\StockMovementBucket;
use App\Enums\StockMovementType;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptItem;
use App\Models\QcCertificate;
use App\Models\QcDevice;
use App\Models\QcInspection;
use App\Models\QcRecordedStockIntake;
use App\Models\QcRecordedStockIntakeRelease;
use App\Models\StockMovement;
use App\Models\StockMovementBucketChange;
use App\Models\User;
use App\Services\Authorization\QcAuthorization;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Recorded Renewed GRN intake and QC custody release boundary. */
class QcRecordedRenewedStockIntakeService
{
    public function __construct(
        private readonly QcAuthorization $authorization,
        private readonly WeightedAverageCostCalculator $costs,
        private readonly InventoryAllocationService $allocation,
    ) {}

    public function canAccess(User $actor): bool
    {
        return $this->authorization->allows($actor, QcPermission::View)
            && $this->authorization->allows($actor, QcPermission::Start);
    }

    public function queueQuery(User $actor): Builder
    {
        $this->authorizeQueue($actor);

        return QcRecordedStockIntake::query()->awaitingStart()
            ->whereHas('product', fn (Builder $query) => $query->where('condition', ProductCondition::Renewed->value))
            ->with(['product:id,sku,name', 'warehouse:id,name', 'receiptItem.receipt:id,reference,received_at'])
            ->addSelect(['completed_quantity' => QcInspection::query()->selectRaw('COUNT(DISTINCT device_id)')
                ->whereColumn('recorded_stock_intake_id', 'qc_recorded_stock_intakes.id')
                ->where('status', QcInspectionStatus::Completed->value)])
            ->orderBy('id');
    }

    /** @return array<int, array<string, mixed>> */
    public function queue(User $actor, array $demandByProductLocation = []): array
    {
        $intakes = $this->queueQuery($actor)->get();
        $priorityByIntake = $this->distributeOrderPriority($intakes, $demandByProductLocation);

        return $intakes->map(fn (QcRecordedStockIntake $intake): array => [
            'intake_id' => $intake->id,
            'product_id' => $intake->product_id,
            'warehouse_id' => $intake->warehouse_id,
            'sku' => $intake->product->sku,
            'product_title' => $intake->product->name,
            'warehouse' => $intake->warehouse->name,
            'grn_reference' => $intake->receiptItem->receipt->reference,
            'received_at' => $intake->receiptItem->receipt->received_at,
            'accepted_quantity' => $intake->accepted_quantity,
            'started_quantity' => $intake->started_quantity,
            'completed_quantity' => (int) $intake->completed_quantity,
            'remaining_quantity' => $intake->accepted_quantity - $intake->started_quantity,
            'order_priority_units' => $priorityByIntake[$intake->id] ?? 0,
        ])->all();
    }

    /** @param Collection<int, QcRecordedStockIntake> $intakes
     * @param  array<int, array<int, int>>  $demandByProductLocation
     * @return array<int, int>
     */
    public function distributeOrderPriority(Collection $intakes, array $demandByProductLocation): array
    {
        $priorityByIntake = $intakes->mapWithKeys(fn (QcRecordedStockIntake $intake): array => [$intake->id => 0])->all();

        foreach ($intakes->groupBy(fn (QcRecordedStockIntake $intake): string => $intake->product_id.':'.$intake->warehouse_id) as $groupKey => $group) {
            [$productId, $warehouseId] = array_map('intval', explode(':', (string) $groupKey, 2));
            $needed = max(0, (int) ($demandByProductLocation[$productId][$warehouseId] ?? 0));
            $group = $group->sortBy('id')->values();
            $rowCount = $group->count();
            if ($needed === 0 || $rowCount === 0) {
                continue;
            }

            // Spread the SKU/location aggregate deterministically over matching GRN rows.
            // The row values therefore add back to, but never multiply, the actual demand.
            $base = intdiv($needed, $rowCount);
            $remainder = $needed % $rowCount;
            foreach ($group as $index => $intake) {
                $priorityByIntake[$intake->id] = $base + ($index < $remainder ? 1 : 0);
            }
        }

        return $priorityByIntake;
    }

    public function assertManualTechnicianStartAllowed(int $productId, int $warehouseId): void
    {
        $pending = QcRecordedStockIntake::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereColumn('started_quantity', '<', 'accepted_quantity')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($pending) {
            throw ValidationException::withMessages([
                'recorded_stock_intake_id' => 'Recorded Renewed stock is already awaiting QC for this Product and Location. Start QC from Recorded Renewed Stock Awaiting QC.',
            ]);
        }
    }

    public function findForStart(int $intakeId, User $actor): QcRecordedStockIntake
    {
        $this->authorizeQueue($actor);

        return QcRecordedStockIntake::query()->awaitingStart()->with([
            'product:id,sku,name,condition,model,brand,brand_id,category,category_id',
            'warehouse:id,name',
        ])
            ->whereHas('product', fn (Builder $query) => $query->where('condition', ProductCondition::Renewed->value))
            ->findOrFail($intakeId);
    }

    public function recordRenewedReceipt(PurchaseReceiptItem $item, int $warehouseId, ProductInventory $inventory, ?int $selectedAccountId): ?QcRecordedStockIntake
    {
        $inventory->loadMissing('product');

        if ($item->accepted_quantity < 1 || $inventory->product->condition !== ProductCondition::Renewed) {
            return null;
        }

        [$account, $method] = $this->allocation->resolveReceiptAccount($inventory, $selectedAccountId);

        return QcRecordedStockIntake::query()->firstOrCreate(
            ['purchase_receipt_item_id' => $item->id],
            [
                'product_id' => $item->product_id,
                'warehouse_id' => $warehouseId,
                'allocation_account_id' => $account->id,
                'allocation_method' => $method,
                'accepted_quantity' => $item->accepted_quantity,
            ],
        );
    }

    public function claimStartSlot(int $intakeId, int $productId, int $warehouseId): QcRecordedStockIntake
    {
        $intake = QcRecordedStockIntake::query()->lockForUpdate()->with('product:id,condition')->findOrFail($intakeId);
        if ($intake->product_id !== $productId || $intake->warehouse_id !== $warehouseId || $intake->product->condition !== ProductCondition::Renewed) {
            throw ValidationException::withMessages(['product_id' => 'Product and location are fixed by the selected recorded Renewed stock source.']);
        }

        $claimed = QcRecordedStockIntake::query()->whereKey($intake->id)
            ->whereColumn('started_quantity', '<', 'accepted_quantity')
            ->increment('started_quantity');
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['recorded_stock_intake_id' => 'All recorded units in this GRN line already have QC jobs started.']);
        }

        return $intake->refresh();
    }

    public function releaseCompletedInspection(QcInspection $inspection, QcDevice $device, QcCertificate $certificate, User $actor, ?string $movementReference): void
    {
        if ($inspection->recorded_stock_intake_id === null || $inspection->qc_source !== 'recorded_renewed_stock') {
            return;
        }

        if (QcRecordedStockIntakeRelease::query()->where('qc_device_id', $device->id)->exists()) {
            return;
        }
        if ($movementReference === null) {
            throw ValidationException::withMessages(['inspection' => 'A stock movement reference could not be reserved for the QC release.']);
        }

        $intake = QcRecordedStockIntake::query()->lockForUpdate()->with('receiptItem')->findOrFail($inspection->recorded_stock_intake_id);
        if ($intake->product_id !== $device->product_id || $intake->warehouse_id !== $inspection->warehouse_id
            || $intake->released_quantity >= $intake->started_quantity) {
            throw ValidationException::withMessages(['inspection' => 'Recorded QC intake release does not match an unreleased started unit.']);
        }

        $inventory = ProductInventory::query()->where('product_id', $intake->product_id)
            ->where('warehouse_id', $intake->warehouse_id)->lockForUpdate()->firstOrFail();
        $unitCost = (string) $intake->receiptItem->inventory_unit_cost;
        $pendingBefore = $inventory->qc_pending_quantity;
        $pendingValueBefore = (string) $inventory->qc_pending_value;
        if ($pendingBefore < 1 || bccomp($pendingValueBefore, $unitCost, 4) < 0) {
            throw ValidationException::withMessages(['inspection' => 'QC Pending inventory is insufficient to release this recorded unit.']);
        }

        $averageBefore = $inventory->average_cost;
        $averageAfter = $this->costs->weightedAverage($inventory->totalOnHand(), $averageBefore, 1, $unitCost);
        $pendingValueAfter = bcsub($pendingValueBefore, $unitCost, 4);
        $inventory->forceFill([
            'qc_pending_quantity' => $pendingBefore - 1,
            'qc_pending_value' => $pendingValueAfter,
            'available_quantity' => $inventory->available_quantity + 1,
            'average_cost' => $averageAfter,
        ])->save();

        $movement = StockMovement::query()->create([
            'reference' => $movementReference,
            'movement_group' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'movement_type' => StockMovementType::RenewedQcRelease,
            'quantity' => 1,
            'available_delta' => 1,
            'reserved_delta' => 0,
            'damaged_delta' => 0,
            'available_before' => $inventory->available_quantity - 1,
            'available_after' => $inventory->available_quantity,
            'reserved_before' => $inventory->reserved_quantity,
            'reserved_after' => $inventory->reserved_quantity,
            'damaged_before' => $inventory->damaged_quantity,
            'damaged_after' => $inventory->damaged_quantity,
            'unit_cost' => $unitCost,
            'average_cost_before' => $averageBefore,
            'average_cost_after' => $averageAfter,
            'source_type' => $inspection->getMorphClass(),
            'source_id' => $inspection->id,
            'reason' => 'Recorded Renewed stock released after successful QC completion',
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);
        StockMovementBucketChange::query()->create([
            'stock_movement_id' => $movement->id,
            'bucket' => StockMovementBucket::QcPending,
            'quantity_delta' => -1,
            'quantity_before' => $pendingBefore,
            'quantity_after' => $pendingBefore - 1,
            'value_delta' => bcsub('0', $unitCost, 4),
            'value_before' => $pendingValueBefore,
            'value_after' => $pendingValueAfter,
        ]);
        $this->allocation->releaseRecordedQcReceiptUnit(
            $intake->receiptItem,
            $inventory,
            $intake->allocation_account_id,
            $intake->allocation_method,
            $actor,
            $inspection,
        );
        $intake->forceFill(['released_quantity' => $intake->released_quantity + 1])->save();
        QcRecordedStockIntakeRelease::query()->create([
            'recorded_stock_intake_id' => $intake->id,
            'qc_device_id' => $device->id,
            'qc_inspection_id' => $inspection->id,
            'qc_certificate_id' => $certificate->id,
            'stock_movement_id' => $movement->id,
            'released_by_user_id' => $actor->id,
            'certificate_version' => $certificate->version,
            'released_at' => now(),
        ]);
    }

    private function authorizeQueue(User $actor): void
    {
        $this->authorization->authorize($actor, QcPermission::View);
        $this->authorization->authorize($actor, QcPermission::Start);
    }
}
