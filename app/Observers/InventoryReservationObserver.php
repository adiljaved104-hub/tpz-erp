<?php

namespace App\Observers;

use App\Exceptions\ImmutableInventoryRecordException;
use App\Models\InventoryReservation;

class InventoryReservationObserver
{
    public function deleting(InventoryReservation $reservation): never
    {
        throw new ImmutableInventoryRecordException('Inventory Reservations cannot be deleted.');
    }
}
