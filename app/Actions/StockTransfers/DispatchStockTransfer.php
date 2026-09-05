<?php

namespace App\Actions\StockTransfers;

use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransfers\StockTransferService;

class DispatchStockTransfer
{
    public function __construct(private readonly StockTransferService $service) {}

    public function handle(StockTransfer $transfer, string $idempotencyKey, User $actor): StockTransfer
    {
        return $this->service->dispatch($transfer, $idempotencyKey, $actor);
    }
}
