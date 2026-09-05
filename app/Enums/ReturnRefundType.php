<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReturnRefundType: string implements HasLabel
{
    case CustomerReturn = 'customer_return';
    case WarrantyRefund = 'warranty_refund';
    case MarketplaceRefund = 'marketplace_refund';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::CustomerReturn => 'Customer Return',
            self::WarrantyRefund => 'Warranty Refund / Final Return',
            self::MarketplaceRefund => 'Marketplace Refund',
            self::Other => 'Other',
        };
    }
}
