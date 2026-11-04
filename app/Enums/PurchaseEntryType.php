<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseEntryType: string implements HasColor, HasLabel
{
    case Standard = 'standard';
    case QuickStock = 'quick_stock';
    case ManualDirect = 'manual_direct';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::QuickStock => 'Quick Stock',
            self::ManualDirect => 'Manual Purchase / Direct Shipment',
        };
    }

    public function getColor(): string
    {
        return $this === self::QuickStock || $this === self::ManualDirect ? 'success' : 'gray';
    }
}
