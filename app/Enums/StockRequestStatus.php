<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case PartiallyApproved = 'partially_approved';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PartiallyApproved => 'Partially Approved',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::PartiallyApproved => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Completed => 'success',
        };
    }
}
