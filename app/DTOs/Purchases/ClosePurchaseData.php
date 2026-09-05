<?php

namespace App\DTOs\Purchases;

final readonly class ClosePurchaseData
{
    public function __construct(public string $reason, public bool $confirmed = false) {}
}
