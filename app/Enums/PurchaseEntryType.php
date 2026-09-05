<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseEntryType: string implements HasColor, HasLabel
{
    case Standard = 'standard';
    case QuickStock = 'quick_stock';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::QuickStock => 'Quick Stock',
        };
    }

    public function getColor(): string
    {
        return $this === self::QuickStock ? 'success' : 'gray';
    }
}
