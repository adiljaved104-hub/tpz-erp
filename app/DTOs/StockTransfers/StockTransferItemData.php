<?php

namespace App\DTOs\StockTransfers;

final readonly class StockTransferItemData
{
    public function __construct(public int $productId, public int $quantity) {}
}
