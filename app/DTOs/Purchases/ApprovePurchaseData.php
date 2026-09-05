<?php

namespace App\DTOs\Purchases;

final readonly class ApprovePurchaseData
{
    public function __construct(public string $reason, public bool $confirmed = false) {}
}
