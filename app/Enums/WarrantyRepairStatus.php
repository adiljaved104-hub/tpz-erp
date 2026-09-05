<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WarrantyRepairStatus: string implements HasColor, HasLabel
{
    case Received = 'received';
    case InspectionPending = 'inspection_pending';
    case UnderInspection = 'under_inspection';
    case SendToTechnician = 'send_to_technician';
    case InRepair = 'in_repair';
    case WaitingForParts = 'waiting_for_parts';
    case RepairCompleted = 'repair_completed';
    case ReceivedBack = 'received_back';
    case QcPending = 'qc_pending';
    case ReadyToReturn = 'ready_to_return';
    case DispatchedBack = 'dispatched_back';
    case Completed = 'completed';
    case CannotRepair = 'cannot_repair';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received, self::InspectionPending, self::UnderInspection => 'gray',
            self::SendToTechnician, self::InRepair => 'info',
            self::WaitingForParts, self::QcPending => 'warning',
            self::RepairCompleted, self::ReceivedBack, self::ReadyToReturn, self::DispatchedBack, self::Completed => 'success',
            self::CannotRepair, self::Cancelled => 'danger',
        };
    }
}
