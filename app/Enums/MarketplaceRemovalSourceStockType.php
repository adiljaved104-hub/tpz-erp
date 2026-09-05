<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceRemovalSourceStockType: string implements HasLabel
{
    case NonSellable = 'marketplace_non_sellable';
    case Sellable = 'marketplace_sellable';

    public function getLabel(): string
    {
        return match ($this) {
            self::NonSellable => 'Marketplace Non-Sellable',
            self::Sellable => 'Marketplace Sellable',
        };
    }
}
