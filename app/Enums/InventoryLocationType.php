<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InventoryLocationType: string implements HasLabel
{
    case CompanyWarehouse = 'company_warehouse';
    case MarketplaceFulfilment = 'marketplace_fulfilment';
    case ThirdPartyLogistics = 'third_party_logistics';
    case Showroom = 'showroom';
    case Transit = 'transit';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::CompanyWarehouse => 'Company Warehouse',
            self::MarketplaceFulfilment => 'Marketplace Fulfilment',
            self::ThirdPartyLogistics => 'Third-Party Logistics',
            self::Showroom => 'Showroom',
            self::Transit => 'Transit',
            self::Other => 'Other',
        };
    }
}
