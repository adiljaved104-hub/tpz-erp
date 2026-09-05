<?php

namespace App\Observers;

use App\Exceptions\ImmutableInventoryRecordException;
use App\Models\StockMovement;

class StockMovementObserver
{
    public function updating(StockMovement $movement): never
    {
        throw new ImmutableInventoryRecordException('Stock Movements cannot be updated.');
    }

    public function deleting(StockMovement $movement): never
    {
        throw new ImmutableInventoryRecordException('Stock Movements cannot be deleted.');
    }
}
