<?php

namespace App\Actions\StockTransfers;

use App\DTOs\StockTransfers\CreateStockTransferData;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransfers\StockTransferService;

class CreateStockTransfer
{
    public function __construct(private readonly StockTransferService $service) {}

    public function handle(CreateStockTransferData $data, User $actor): StockTransfer
    {
        return $this->service->createDraft($data, $actor);
    }
}
