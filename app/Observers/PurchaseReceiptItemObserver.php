<?php

namespace App\Observers;

use App\Exceptions\ImmutablePurchaseException;
use App\Models\PurchaseReceiptItem;

class PurchaseReceiptItemObserver
{
    public function updating(PurchaseReceiptItem $item): never
    {
        throw new ImmutablePurchaseException('Goods Received Note items cannot be updated.');
    }

    public function deleting(PurchaseReceiptItem $item): never
    {
        throw new ImmutablePurchaseException('Goods Received Note items cannot be deleted.');
    }
}
