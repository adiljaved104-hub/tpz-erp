<?php

namespace App\Observers;

use App\Exceptions\HardDeletionProhibitedException;
use App\Models\Warehouse;

class WarehouseObserver
{
    public function deleting(Warehouse $warehouse): never
    {
        throw new HardDeletionProhibitedException('Warehouses cannot be hard-deleted. Set the Warehouse inactive when safeguards allow.');
    }
}
