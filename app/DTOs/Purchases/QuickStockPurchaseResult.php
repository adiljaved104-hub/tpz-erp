<?php

namespace App\DTOs\Purchases;

use App\Models\Purchase;
use App\Models\PurchaseReceipt;

final readonly class QuickStockPurchaseResult
{
    public function __construct(
        public Purchase $purchase,
        public PurchaseReceipt $receipt,
        public bool $replayed = false,
    ) {}
}
