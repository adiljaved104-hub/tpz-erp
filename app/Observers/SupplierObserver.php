<?php

namespace App\Observers;

use App\Exceptions\HardDeletionProhibitedException;
use App\Models\Supplier;

class SupplierObserver
{
    public function deleting(Supplier $supplier): never
    {
        throw new HardDeletionProhibitedException('Suppliers cannot be hard-deleted. Set the Supplier inactive instead.');
    }
}
