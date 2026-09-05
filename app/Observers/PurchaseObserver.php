<?php

namespace App\Observers;

use App\Exceptions\ImmutablePurchaseException;
use App\Models\Purchase;

class PurchaseObserver
{
    public function deleting(Purchase $purchase): never
    {
        throw new ImmutablePurchaseException('Purchases cannot be deleted.');
    }
}
