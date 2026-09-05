<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockTransferStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function getLabel(): string
    {
        return $this === self::Dispatched ? 'In Transit' : str($this->value)->headline()->toString();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Dispatched, self::Returned => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }
}
