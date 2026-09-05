<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DamagedStockSource: string implements HasLabel
{
    case CustomerReturn = 'customer_return';
    case SupplierReceipt = 'supplier_receipt';
    case WarehouseDamage = 'internal_warehouse';
    case DeliveryTransit = 'courier_transit';
    case WarrantyService = 'warranty_service';
    case Other = 'other_adjustment';

    public function getLabel(): string
    {
        return match ($this) {
            self::CustomerReturn => 'Customer Return',
            self::SupplierReceipt => 'Supplier Receipt',
            self::WarehouseDamage => 'Warehouse Damage',
            self::DeliveryTransit => 'Delivery / Transit',
            self::WarrantyService => 'Warranty / Service',
            self::Other => 'Other',
        };
    }
}
