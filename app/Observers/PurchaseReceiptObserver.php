<?php

namespace App\Observers;

use App\Exceptions\ImmutablePurchaseException;
use App\Models\PurchaseReceipt;

class PurchaseReceiptObserver
{
    public function updating(PurchaseReceipt $receipt): never
    {
        throw new ImmutablePurchaseException('Goods Received Notes cannot be updated.');
    }

    public function deleting(PurchaseReceipt $receipt): never
    {
        throw new ImmutablePurchaseException('Goods Received Notes cannot be deleted.');
    }
}
