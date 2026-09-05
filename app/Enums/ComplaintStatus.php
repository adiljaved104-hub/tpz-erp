<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplaintStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case WaitingForMarketplace = 'waiting_for_marketplace';
    case WaitingForInternalTeam = 'waiting_for_internal_team';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open, self::WaitingForCustomer, self::WaitingForMarketplace, self::WaitingForInternalTeam => 'warning',
            self::InProgress => 'info',
            self::Resolved, self::Closed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
