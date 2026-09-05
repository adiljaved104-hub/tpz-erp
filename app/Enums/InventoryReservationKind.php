<?php

namespace App\Enums;

enum InventoryReservationKind: string
{
    case BaseProduct = 'base_product';
    case UpgradeComponent = 'upgrade_component';

    public function label(): string
    {
        return match ($this) {
            self::BaseProduct => 'Base Product',
            self::UpgradeComponent => 'Upgrade Component',
        };
    }
}
