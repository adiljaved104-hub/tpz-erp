<?php

namespace App\Services\Qc;

use App\Models\QcDeviceClaim;
use App\Models\QcOrderAssignment;
use App\Models\QcStockTransferAssignment;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use LogicException;

class QcDeviceClaimService
{
    public function assertAvailable(int $deviceId, ?QcOrderAssignment $orderAssignment = null, ?QcStockTransferAssignment $transferAssignment = null, string $field = 'code'): void
    {
        $claim = QcDeviceClaim::query()->active()->where('active_device_id', $deviceId)->lockForUpdate()->first();
        if (! $claim) {
            return;
        }

        if (($orderAssignment && $claim->qc_order_assignment_id === $orderAssignment->id)
            || ($transferAssignment && $claim->qc_stock_transfer_assignment_id === $transferAssignment->id)) {
            return;
        }

        throw ValidationException::withMessages([$field => 'This QC device is already assigned to another Order or Stock Transfer.']);
    }

    public function claimOrderAssignment(QcOrderAssignment $assignment, User $actor): QcDeviceClaim
    {
        $this->assertAvailable($assignment->qc_device_id, $assignment, field: 'certificate_id');

        return QcDeviceClaim::query()->create([
            'qc_device_id' => $assignment->qc_device_id,
            'active_device_id' => $assignment->qc_device_id,
            'qc_order_assignment_id' => $assignment->id,
            'claimed_by_user_id' => $actor->id,
            'claimed_at' => $assignment->assigned_at,
        ]);
    }

    public function claimStockTransferAssignment(QcStockTransferAssignment $assignment, User $actor): QcDeviceClaim
    {
        $this->assertAvailable($assignment->qc_device_id, transferAssignment: $assignment);

        return QcDeviceClaim::query()->create([
            'qc_device_id' => $assignment->qc_device_id,
            'active_device_id' => $assignment->qc_device_id,
            'qc_stock_transfer_assignment_id' => $assignment->id,
            'claimed_by_user_id' => $actor->id,
            'claimed_at' => $assignment->assigned_at,
        ]);
    }

    public function releaseOrderAssignment(QcOrderAssignment $assignment, string $reason, User $actor): void
    {
        $claim = QcDeviceClaim::query()->active()->where('qc_order_assignment_id', $assignment->id)->lockForUpdate()->first();
        $this->release($claim, $assignment->qc_device_id, $reason, $actor);
    }

    public function releaseStockTransferAssignment(QcStockTransferAssignment $assignment, string $reason, User $actor): void
    {
        $claim = QcDeviceClaim::query()->active()->where('qc_stock_transfer_assignment_id', $assignment->id)->lockForUpdate()->first();
        $this->release($claim, $assignment->qc_device_id, $reason, $actor);
    }

    private function release(?QcDeviceClaim $claim, int $deviceId, string $reason, User $actor): void
    {
        if (! $claim || $claim->active_device_id !== $deviceId || $claim->qc_device_id !== $deviceId) {
            throw new LogicException('The active global QC device claim is missing or inconsistent.');
        }

        $claim->fill(['active_device_id' => null, 'released_at' => now(), 'released_by_user_id' => $actor->id, 'release_reason' => trim($reason)])->save();
    }
}
