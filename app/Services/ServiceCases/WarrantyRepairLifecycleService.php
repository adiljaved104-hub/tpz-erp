<?php

namespace App\Services\ServiceCases;

use App\Enums\WarrantyRepairStatus;
use App\Models\WarrantyRepair;

class WarrantyRepairLifecycleService
{
    /** @return list<WarrantyRepairStatus> */
    public function validTransitions(WarrantyRepair $case): array
    {
        if ($this->isInternalCompanyOwnedRepair($case)) {
            return $this->internalTransitions($case);
        }

        if ($case->status === WarrantyRepairStatus::CannotRepair) {
            return $case->moved_to_damaged_at === null ? [] : [WarrantyRepairStatus::Completed];
        }

        return match ($case->status) {
            WarrantyRepairStatus::Received, WarrantyRepairStatus::InspectionPending => [WarrantyRepairStatus::UnderInspection],
            WarrantyRepairStatus::UnderInspection => [WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::ReadyToReturn, WarrantyRepairStatus::CannotRepair],
            WarrantyRepairStatus::SendToTechnician => [WarrantyRepairStatus::InRepair],
            WarrantyRepairStatus::InRepair => [WarrantyRepairStatus::WaitingForParts, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::CannotRepair],
            WarrantyRepairStatus::WaitingForParts => [WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::CannotRepair],
            WarrantyRepairStatus::RepairCompleted => [WarrantyRepairStatus::ReceivedBack],
            WarrantyRepairStatus::ReceivedBack => [WarrantyRepairStatus::QcPending],
            WarrantyRepairStatus::QcPending => [WarrantyRepairStatus::ReadyToReturn, WarrantyRepairStatus::CannotRepair],
            WarrantyRepairStatus::ReadyToReturn => [WarrantyRepairStatus::DispatchedBack],
            WarrantyRepairStatus::DispatchedBack => [WarrantyRepairStatus::Completed],
            default => [],
        };
    }

    public function allows(WarrantyRepair $case, WarrantyRepairStatus $status): bool
    {
        return in_array($status, $this->validTransitions($case), true);
    }

    public function actionLabel(WarrantyRepairStatus $status, ?WarrantyRepair $case = null): string
    {
        if ($case !== null && $this->isInternalCompanyOwnedRepair($case)) {
            if ($status === WarrantyRepairStatus::ReadyToReturn) {
                return 'Pass';
            }
            if ($status === WarrantyRepairStatus::CannotRepair) {
                return $case->status === WarrantyRepairStatus::QcPending ? 'Fail' : 'Cannot Repair / Close Case';
            }
            if ($status === WarrantyRepairStatus::Completed) {
                return 'Close Case';
            }
        }

        return match ($status) {
            WarrantyRepairStatus::UnderInspection => 'Inspect',
            WarrantyRepairStatus::SendToTechnician => 'Send to Technician',
            WarrantyRepairStatus::InRepair => $case?->status === WarrantyRepairStatus::WaitingForParts ? 'Back to Repair' : 'Start Repair',
            WarrantyRepairStatus::WaitingForParts => 'Waiting for Parts',
            WarrantyRepairStatus::RepairCompleted => 'Repair Completed',
            WarrantyRepairStatus::ReceivedBack => 'Receive Back',
            WarrantyRepairStatus::QcPending => 'QC / Inspect',
            WarrantyRepairStatus::ReadyToReturn => 'Ready to Return',
            WarrantyRepairStatus::CannotRepair => 'Cannot Repair',
            WarrantyRepairStatus::DispatchedBack => 'Dispatch Back',
            WarrantyRepairStatus::Completed => $case?->moved_to_damaged_at === null ? 'Complete' : 'Close Case',
            default => $status->getLabel(),
        };
    }

    public function isInternalCompanyOwnedRepair(WarrantyRepair $case): bool
    {
        return $case->isInternalCompanyOwnedRepair();
    }

    /** @return list<WarrantyRepairStatus> */
    private function internalTransitions(WarrantyRepair $case): array
    {
        return match ($case->status) {
            WarrantyRepairStatus::Received, WarrantyRepairStatus::InspectionPending, WarrantyRepairStatus::UnderInspection => [WarrantyRepairStatus::SendToTechnician],
            WarrantyRepairStatus::SendToTechnician => [WarrantyRepairStatus::InRepair],
            WarrantyRepairStatus::InRepair => [WarrantyRepairStatus::WaitingForParts, WarrantyRepairStatus::RepairCompleted],
            WarrantyRepairStatus::WaitingForParts => [WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted],
            WarrantyRepairStatus::RepairCompleted => [WarrantyRepairStatus::ReceivedBack],
            WarrantyRepairStatus::ReceivedBack => [WarrantyRepairStatus::QcPending],
            WarrantyRepairStatus::QcPending => [WarrantyRepairStatus::ReadyToReturn, WarrantyRepairStatus::CannotRepair],
            WarrantyRepairStatus::ReadyToReturn => [WarrantyRepairStatus::Completed],
            default => [],
        };
    }
}
