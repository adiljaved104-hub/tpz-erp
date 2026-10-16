<?php

namespace App\Services\Qc;

use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\QcPermission;
use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Models\QcCertificate;
use App\Models\QcDevice;
use App\Models\QcDeviceClaim;
use App\Models\QcStockTransferAssignment;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QcStockTransferAssignmentService
{
    public function assignScan(
        StockTransfer $transfer,
        string $code,
        User $actor,
        ?string $physicalSerial = null,
        ?int $stockTransferItemId = null,
    ): QcStockTransferAssignment {
        $this->authorizeAssignment($actor, $transfer);
        Validator::make([
            'code' => $code,
            'physical_serial' => $physicalSerial,
            'stock_transfer_item_id' => $stockTransferItemId,
        ], [
            'code' => ['required', 'string', 'max:2048'],
            'physical_serial' => ['nullable', 'string', 'max:100'],
            'stock_transfer_item_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $scanned = app(QcCertificateScanResolver::class)->resolve($code, 'code');

        return DB::transaction(function () use ($transfer, $scanned, $physicalSerial, $stockTransferItemId, $actor): QcStockTransferAssignment {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->authorizeAssignment($actor, $transfer);
            if ($transfer->status !== StockTransferStatus::Draft) {
                throw ValidationException::withMessages(['stock_transfer_id' => 'QC units may only be assigned while the Stock Transfer is Draft.']);
            }

            $locations = Warehouse::query()->whereIn('id', [$transfer->source_warehouse_id, $transfer->destination_warehouse_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locations->get($transfer->source_warehouse_id);
            $destination = $locations->get($transfer->destination_warehouse_id);
            if (! $source || ! $destination || $destination->location_type !== InventoryLocationType::MarketplaceFulfilment) {
                throw ValidationException::withMessages(['stock_transfer_id' => 'QC transfer assignment is only available for a Marketplace Fulfilment destination.']);
            }

            $items = StockTransferItem::query()->where('stock_transfer_id', $transfer->id)->with('product')->orderBy('id')->lockForUpdate()->get();
            $device = QcDevice::query()->lockForUpdate()->findOrFail($scanned->device_id);
            $certificate = QcCertificate::query()->lockForUpdate()->findOrFail($scanned->id);
            $inspection = $certificate->inspection()->lockForUpdate()->firstOrFail();
            $certificate->setRelation('device', $device)->setRelation('inspection', $inspection);

            if (filled($physicalSerial) && QcInspectionService::serialKey($physicalSerial) !== QcInspectionService::serialKey($device->serial)) {
                throw ValidationException::withMessages(['physical_serial' => 'The physical Serial / IMEI does not match the scanned QC device. Do not move this unit.']);
            }

            app(QcCertificateEligibilityService::class)->assertValidForTransfer($certificate, $device->product_id, $source->id, locked: true);

            $activeAssignments = QcStockTransferAssignment::query()->active()->where('stock_transfer_id', $transfer->id)->get()->groupBy('stock_transfer_item_id');
            $existing = QcStockTransferAssignment::query()->where('active_device_id', $device->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->stock_transfer_id === (int) $transfer->id
                    && ($stockTransferItemId === null || $existing->stock_transfer_item_id === $stockTransferItemId)
                    && $existing->qc_certificate_id === $certificate->id) {
                    $existingItem = $items->first(fn (StockTransferItem $candidate): bool => (int) $candidate->id === (int) $existing->stock_transfer_item_id
                        && (int) $candidate->product_id === (int) $device->product_id
                        && app(RenewedQcRequirement::class)->conditionRequires($candidate->product?->condition));
                    if (! $existingItem || $existing->certificate_version !== $certificate->version) {
                        throw ValidationException::withMessages(['code' => 'This QC device assignment no longer matches an eligible Transfer Item and current certificate.']);
                    }
                    app(QcDeviceClaimService::class)->assertAvailable($device->id, transferAssignment: $existing);

                    return $existing->load(['transferItem', 'device', 'certificate.inspection']);
                }
                throw ValidationException::withMessages(['code' => 'This QC device is already assigned to another Stock Transfer or Transfer Item. Release the Draft assignment before reusing it.']);
            }

            $item = $this->matchItem($items, $activeAssignments, (int) $device->product_id, $stockTransferItemId);
            app(QcDeviceClaimService::class)->assertAvailable($device->id);
            $lineAssignments = $activeAssignments->get($item->id, collect());
            if ($lineAssignments->count() >= (int) $item->quantity) {
                throw ValidationException::withMessages(['stock_transfer_item_id' => 'All required QC units are already assigned to this Transfer Item.']);
            }

            $assignment = QcStockTransferAssignment::query()->create([
                'stock_transfer_id' => $transfer->id,
                'stock_transfer_item_id' => $item->id,
                'qc_device_id' => $device->id,
                'qc_certificate_id' => $certificate->id,
                'active_device_id' => $device->id,
                'certificate_version' => $certificate->version,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
            ]);
            app(QcDeviceClaimService::class)->claimStockTransferAssignment($assignment, $actor);
            app(ActivityLogger::class)->log('qc.stock_transfer_device_assigned', $actor, $assignment, [
                'transfer_reference' => $transfer->reference,
                'stock_transfer_item_id' => $item->id,
                'qc_reference' => $device->reference,
                'certificate_version' => $certificate->version,
            ]);

            return $assignment->load(['transferItem', 'device', 'certificate.inspection']);
        }, 5);
    }

    public function release(StockTransfer $transfer, QcStockTransferAssignment $assignment, string $reason, User $actor): QcStockTransferAssignment
    {
        $this->authorizeRelease($actor, $transfer);
        $reason = Validator::make(['release_reason' => trim($reason)], ['release_reason' => ['required', 'string', 'max:2000']])->validate()['release_reason'];

        return DB::transaction(function () use ($transfer, $assignment, $reason, $actor): QcStockTransferAssignment {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->authorizeRelease($actor, $transfer);
            if ($transfer->status !== StockTransferStatus::Draft) {
                throw ValidationException::withMessages(['assignment_id' => 'QC device assignments cannot be released after Stock Transfer dispatch.']);
            }

            $item = StockTransferItem::query()->where('stock_transfer_id', $transfer->id)->lockForUpdate()->findOrFail($assignment->stock_transfer_item_id);
            QcDevice::query()->whereKey($assignment->qc_device_id)->lockForUpdate()->firstOrFail();
            $assignment = QcStockTransferAssignment::query()->where('stock_transfer_id', $transfer->id)->lockForUpdate()->findOrFail($assignment->id);
            if ($assignment->released_at !== null) {
                return $assignment;
            }

            $this->releaseLocked($assignment, $reason, $actor);

            return $assignment->refresh()->load(['transferItem', 'device', 'certificate.inspection']);
        }, 5);
    }

    /** Called inside StockTransferService::cancel's already-open transaction. */
    public function releaseForDraftCancellation(StockTransfer $transfer, string $reason, User $actor): void
    {
        StockTransferItem::query()->where('stock_transfer_id', $transfer->id)->orderBy('id')->lockForUpdate()->get();
        $assignments = QcStockTransferAssignment::query()->active()->where('stock_transfer_id', $transfer->id)->orderBy('qc_device_id')->get();
        $this->lockDevices($assignments);
        $assignments = QcStockTransferAssignment::query()->active()->where('stock_transfer_id', $transfer->id)->orderBy('qc_device_id')->lockForUpdate()->get();

        foreach ($assignments as $assignment) {
            $this->releaseLocked($assignment, $reason, $actor);
        }
    }

    /** Called only after the existing inventory return-to-source posting has succeeded in its transaction. */
    public function releaseAfterReturnToSource(StockTransfer $transfer, string $reason, User $actor): void
    {
        $assignments = QcStockTransferAssignment::query()->active()->where('stock_transfer_id', $transfer->id)->orderBy('qc_device_id')->get();
        $this->lockDevices($assignments);
        $assignments = QcStockTransferAssignment::query()->active()->where('stock_transfer_id', $transfer->id)->orderBy('qc_device_id')->lockForUpdate()->get();

        foreach ($assignments as $assignment) {
            $this->releaseLocked($assignment, $reason, $actor);
        }
    }

    private function matchItem(Collection $items, Collection $activeAssignments, int $productId, ?int $selectedId): StockTransferItem
    {
        $eligible = $items->filter(fn (StockTransferItem $item): bool => (int) $item->product_id === $productId
            && app(RenewedQcRequirement::class)->conditionRequires($item->product?->condition)
            && ($activeAssignments->get((int) $item->id, collect())->count() < (int) $item->quantity));

        if ($selectedId !== null) {
            $item = $items->firstWhere('id', $selectedId);
            if (! $item || ! $eligible->contains(fn (StockTransferItem $candidate): bool => (int) $candidate->id === (int) $item->id)) {
                throw ValidationException::withMessages(['stock_transfer_item_id' => 'Select a remaining Renewed Transfer Item that matches this QC device Product.']);
            }

            return $item;
        }

        $item = $eligible->first();
        if (! $item) {
            throw ValidationException::withMessages(['code' => 'No remaining Renewed Transfer Item matches this QC device Product.']);
        }

        return $item;
    }

    private function authorizeAssignment(User $actor, StockTransfer $transfer): void
    {
        $viewPermission = $actor->employee?->role === EmployeeRole::Technician
            ? QcPermission::ViewMarketplaceTransferAssignments
            : QcPermission::ViewOrderAssignments;
        app(QcAuthorization::class)->authorize($actor, $viewPermission);
        app(QcAuthorization::class)->authorize($actor, QcPermission::AssignMarketplaceTransferDevice);
        throw_unless(app(QcTransferDemandService::class)->canViewTransfer($actor, $transfer), AuthorizationException::class);
    }

    private function authorizeRelease(User $actor, StockTransfer $transfer): void
    {
        app(StockTransferAuthorization::class)->authorize($actor, StockTransferPermission::Dispatch, $transfer);
        app(QcAuthorization::class)->authorize($actor, QcPermission::ViewOrderAssignments);
        app(QcAuthorization::class)->authorize($actor, QcPermission::ReleaseMarketplaceTransferDevice);
    }

    private function lockDevices($assignments): void
    {
        $deviceIds = $assignments->pluck('qc_device_id')->unique()->sort()->values();
        if ($deviceIds->isNotEmpty()) {
            QcDevice::query()->whereIn('id', $deviceIds)->orderBy('id')->lockForUpdate()->get();
            QcDeviceClaim::query()->active()->whereIn('active_device_id', $deviceIds)->orderBy('active_device_id')->lockForUpdate()->get();
        }
    }

    private function releaseLocked(QcStockTransferAssignment $assignment, string $reason, User $actor): void
    {
        if ($assignment->released_at !== null) {
            return;
        }

        $assignment->fill(['active_device_id' => null, 'released_at' => now(), 'released_by_user_id' => $actor->id, 'release_reason' => trim($reason)])->save();
        app(QcDeviceClaimService::class)->releaseStockTransferAssignment($assignment, $reason, $actor);
        app(ActivityLogger::class)->log('qc.stock_transfer_device_released', $actor, $assignment, [
            'transfer_reference' => $assignment->transfer()->value('reference'),
            'stock_transfer_item_id' => $assignment->stock_transfer_item_id,
            'qc_reference' => $assignment->device()->value('reference'),
            'release_reason_recorded' => true,
        ]);
    }
}
