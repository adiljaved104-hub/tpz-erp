<?php

namespace App\Enums;

enum InventoryReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Fulfilled = 'fulfilled';
}
