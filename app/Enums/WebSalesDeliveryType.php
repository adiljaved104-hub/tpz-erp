<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebSalesDeliveryType: string implements HasLabel
{
    case Courier = 'courier';
    case ShopPickup = 'shop_pickup';

    public function getLabel(): string
    {
        return match ($this) {
            self::Courier => 'Courier',
            self::ShopPickup => 'Shop Pickup',
        };
    }
}
