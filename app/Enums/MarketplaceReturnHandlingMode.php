<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceReturnHandlingMode: string implements HasLabel
{
    case HoldNonSellableAtMarketplace = 'marketplace_restock_or_hold_non_sellable';
    case AutoReturnNonSellableToCompany = 'marketplace_restock_or_auto_return';
    case ReturnDirectlyToCompany = 'company_direct_return';

    public function getLabel(): string
    {
        return match ($this) {
            self::HoldNonSellableAtMarketplace => 'Hold Non-Sellable at Marketplace',
            self::AutoReturnNonSellableToCompany => 'Auto Return Non-Sellable to Company',
            self::ReturnDirectlyToCompany => 'Return Directly to Company',
        };
    }
}
