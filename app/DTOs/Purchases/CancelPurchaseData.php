<?php

namespace App\DTOs\Purchases;

final readonly class CancelPurchaseData
{
    public function __construct(public string $reason) {}
}
