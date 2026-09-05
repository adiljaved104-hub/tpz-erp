<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\QuickStockPurchaseData;
use App\DTOs\Purchases\QuickStockPurchaseResult;
use App\Models\User;
use App\Services\Purchases\QuickStockPurchaseService;

class QuickStockPurchase
{
    public function __construct(private readonly QuickStockPurchaseService $service) {}

    public function handle(QuickStockPurchaseData $data, User $actor): QuickStockPurchaseResult
    {
        return $this->service->createAndReceive($data, $actor);
    }
}
