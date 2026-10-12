<?php

namespace App\Services\Qc;

use App\Enums\QcInspectionStatus;
use App\Models\QcCertificate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Shared, read-only pool of completed/current QC devices not claimed by any workflow. */
class QcAvailableCertificateService
{
    /** @return Collection<int, QcCertificate> */
    public function currentUnclaimed(): Collection
    {
        return QcCertificate::query()
            ->with(['device', 'inspection'])
            ->whereHas('inspection', fn (Builder $query) => $query->where('status', QcInspectionStatus::Completed->value))
            ->whereDoesntHave('device.claims', fn (Builder $query) => $query->active())
            ->whereDoesntHave('device.orderAssignments', fn (Builder $query) => $query->active())
            ->whereDoesntHave('device.stockTransferAssignments', fn (Builder $query) => $query->active())
            ->whereDoesntHave('device.inspections', fn (Builder $query) => $query->whereNotNull('active_device_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('qc_certificates as newer')
                ->whereColumn('newer.device_id', 'qc_certificates.device_id')
                ->whereColumn('newer.version', '>', 'qc_certificates.version'))
            ->orderBy('device_id')
            ->orderBy('id')
            // Malformed historical data with multiple current rows still yields one physical unit.
            ->get()
            ->unique('device_id')
            ->values();
    }
}
