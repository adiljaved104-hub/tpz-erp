<?php

namespace App\Services\Qc;

use App\Enums\InventoryLocationType;
use App\Enums\ProductCondition;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Models\QcDevice;
use App\Models\QcDeviceClaim;
use App\Models\QcStockTransferAssignment;
use App\Models\StockTransfer;
use App\Models\Warehouse;

class QcStockTransferReadinessService
{
    public function forTransfer(StockTransfer $transfer): array
    {
        return $this->readiness($transfer, lock: false);
    }

    public function assertReadyForDispatch(StockTransfer $transfer): void
    {
        $readiness = $this->readiness($transfer, lock: true);
        if ($readiness['status'] === 'not_required' || $readiness['status'] === 'ready') {
            return;
        }

        throw new InvalidStockTransferTransitionException(sprintf(
            'Cannot dispatch %s. Renewed QC incomplete: %d / %d valid QC units assigned.',
            $transfer->reference,
            $readiness['valid'],
            $readiness['required'],
        ));
    }

    public function isRequired(StockTransfer $transfer): bool
    {
        $destination = $transfer->relationLoaded('destinationWarehouse')
            ? $transfer->destinationWarehouse
            : Warehouse::query()->find($transfer->destination_warehouse_id);

        if ($destination?->location_type !== InventoryLocationType::MarketplaceFulfilment) {
            return false;
        }

        return $transfer->items()->whereHas('product', fn ($query) => $query->where('condition', ProductCondition::Renewed->value))->exists();
    }

    private function readiness(StockTransfer $transfer, bool $lock): array
    {
        $destination = $transfer->relationLoaded('destinationWarehouse')
            ? $transfer->destinationWarehouse
            : Warehouse::query()->find($transfer->destination_warehouse_id);
        $source = $transfer->relationLoaded('sourceWarehouse')
            ? $transfer->sourceWarehouse
            : Warehouse::query()->find($transfer->source_warehouse_id);
        $items = $transfer->items()->with('product')->orderBy('id')->get();
        $requires = $destination?->location_type === InventoryLocationType::MarketplaceFulfilment;

        $candidateAssignments = $requires
            ? QcStockTransferAssignment::query()->active()->where('stock_transfer_id', $transfer->id)->orderBy('qc_device_id')->get()
            : collect();

        if ($lock && $candidateAssignments->isNotEmpty()) {
            $deviceIds = $candidateAssignments->pluck('qc_device_id')->unique()->sort()->values();
            QcDevice::query()->whereIn('id', $deviceIds)->orderBy('id')->lockForUpdate()->get();
            $candidateAssignments = QcStockTransferAssignment::query()->active()
                ->where('stock_transfer_id', $transfer->id)->orderBy('qc_device_id')->lockForUpdate()->get();
            $claimDeviceIds = $candidateAssignments->pluck('qc_device_id')->unique()->sort()->values();
            QcDeviceClaim::query()->active()->whereIn('active_device_id', $claimDeviceIds)->orderBy('active_device_id')->lockForUpdate()->get();
        }

        $assignmentsByItem = $candidateAssignments->groupBy('stock_transfer_item_id');
        $eligibility = app(QcCertificateEligibilityService::class);
        $lines = $items->map(function ($item) use ($requires, $source, $assignmentsByItem, $eligibility, $lock): array {
            $required = $requires && $item->product?->condition === ProductCondition::Renewed ? max(0, (int) $item->quantity) : 0;
            $assignments = $assignmentsByItem->get($item->id, collect());
            $valid = $required === 0 ? 0 : $assignments->filter(function (QcStockTransferAssignment $assignment) use ($eligibility, $item, $source, $lock): bool {
                $assignment->loadMissing(['device', 'certificate.inspection']);
                $claimExists = QcDeviceClaim::query()->active()
                    ->where('active_device_id', $assignment->qc_device_id)
                    ->where('qc_stock_transfer_assignment_id', $assignment->id)
                    ->exists();

                return $claimExists && $assignment->active_device_id === $assignment->qc_device_id
                    && $assignment->certificate_version === $assignment->certificate?->version
                    && $source !== null
                    && $eligibility->isValidForTransfer($assignment->certificate, $item->product_id, $source->id, $lock);
            })->count();
            $assigned = $required === 0 ? 0 : $assignments->count();
            $remaining = max(0, $required - $valid);
            $status = match (true) {
                $required === 0 => 'not_required',
                $assigned > $valid => 'attention_required',
                $valid >= $required => 'ready',
                $assigned > 0 => 'partially_assigned',
                default => 'pending_qc',
            };

            return [
                'stock_transfer_item_id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'product' => $item->product_name,
                'condition' => $item->product?->condition?->value ?? $item->product?->condition,
                'quantity' => (int) $item->quantity,
                'required' => $required,
                'assigned' => $assigned,
                'valid' => $valid,
                'remaining' => $remaining,
                'status' => $status,
            ];
        });

        $required = $lines->sum('required');
        $assigned = $lines->sum('assigned');
        $valid = $lines->sum('valid');
        $status = match (true) {
            $required === 0 => 'not_required',
            $lines->contains(fn (array $line): bool => $line['status'] === 'attention_required') => 'attention_required',
            $valid >= $required => 'ready',
            $assigned > 0 => 'partially_assigned',
            default => 'pending_qc',
        };

        return [
            'status' => $status,
            'required' => $required,
            'assigned' => $assigned,
            'valid' => $valid,
            'remaining' => max(0, $required - $valid),
            'lines' => $lines->all(),
        ];
    }
}
