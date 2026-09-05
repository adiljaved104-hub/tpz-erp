<?php

namespace App\DTOs\Orders;

final readonly class CancelOrderData
{
    public function __construct(public string $reason, public string $idempotencyKey) {}
}
