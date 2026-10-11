<?php

namespace App\Services\Qc;

use App\Enums\QcInspectionStatus;
use App\Models\QcCertificate;
use App\Models\QcInspection;
use Illuminate\Validation\ValidationException;

class QcCertificateEligibilityService
{
    public function isCurrent(QcCertificate $certificate, bool $locked = false): bool
    {
        $inspection = $certificate->relationLoaded('inspection') ? $certificate->inspection : $certificate->inspection()->first();
        if (! $inspection || $inspection->status !== QcInspectionStatus::Completed) {
            return false;
        }

        $newer = QcCertificate::query()->where('device_id', $certificate->device_id)->where('version', '>', $certificate->version);
        $reinspection = QcInspection::query()->where('active_device_id', $certificate->device_id);

        if ($locked) {
            return $newer->lockForUpdate()->get()->isEmpty() && $reinspection->lockForUpdate()->get()->isEmpty();
        }

        return ! $newer->exists() && ! $reinspection->exists();
    }

    public function assertCurrent(QcCertificate $certificate, bool $locked = false): void
    {
        if (! $this->isCurrent($certificate, $locked)) {
            throw ValidationException::withMessages(['certificate_id' => 'QC must be completed and current, with no reinspection pending.']);
        }
    }

    public function isValidForTransfer(QcCertificate $certificate, int $productId, int $sourceWarehouseId, bool $locked = false): bool
    {
        $inspection = $certificate->relationLoaded('inspection') ? $certificate->inspection : $certificate->inspection()->first();
        $device = $certificate->relationLoaded('device') ? $certificate->device : $certificate->device()->first();

        return $inspection !== null && $device !== null
            && $device->product_id === $productId
            && $inspection->warehouse_id === $sourceWarehouseId
            && $this->isCurrent($certificate, $locked);
    }

    public function assertValidForTransfer(QcCertificate $certificate, int $productId, int $sourceWarehouseId, bool $locked = false): void
    {
        $inspection = $certificate->relationLoaded('inspection') ? $certificate->inspection : $certificate->inspection()->first();
        $device = $certificate->relationLoaded('device') ? $certificate->device : $certificate->device()->first();
        $this->assertCurrent($certificate, $locked);

        if ($device?->product_id !== $productId) {
            throw ValidationException::withMessages(['code' => 'The QC device Product does not match this Stock Transfer Item.']);
        }
        if ($inspection?->warehouse_id !== $sourceWarehouseId) {
            throw ValidationException::withMessages(['code' => 'The QC inspection warehouse must match the Stock Transfer source warehouse.']);
        }
    }
}
