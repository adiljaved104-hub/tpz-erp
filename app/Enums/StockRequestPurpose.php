<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockRequestPurpose: string implements HasLabel
{
    case ForOrder = 'for_order';
    case PermanentTransfer = 'permanent_transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::ForOrder => 'For Order',
            self::PermanentTransfer => 'Permanent Transfer',
        };
    }
}
