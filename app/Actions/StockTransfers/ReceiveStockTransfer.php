<?php

namespace App\Actions\StockTransfers;

use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransfers\StockTransferService;

class ReceiveStockTransfer
{
    public function __construct(private readonly StockTransferService $service) {}

    public function handle(StockTransfer $transfer, string $idempotencyKey, User $actor): StockTransfer
    {
        return $this->service->receive($transfer, $idempotencyKey, $actor);
    }
}
