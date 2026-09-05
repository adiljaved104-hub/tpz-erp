<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockMovementBucket: string implements HasLabel
{
    case MarketplaceNonSellable = 'marketplace_non_sellable';
    case QcPending = 'qc_pending';

    public function getLabel(): string
    {
        return match ($this) {
            self::MarketplaceNonSellable => 'Marketplace Non-Sellable',
            self::QcPending => 'QC Pending',
        };
    }
}
