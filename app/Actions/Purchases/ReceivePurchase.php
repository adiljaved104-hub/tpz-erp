<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\ReceivePurchaseData;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Services\Purchases\PurchaseReceivingService;

class ReceivePurchase
{
    public function __construct(private readonly PurchaseReceivingService $receiving) {}

    public function handle(Purchase $purchase, ReceivePurchaseData $data, User $actor): PurchaseReceipt
    {
        return $this->receiving->receive($purchase, $data, $actor);
    }
}
