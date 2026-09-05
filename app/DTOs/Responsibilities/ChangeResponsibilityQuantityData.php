<?php

namespace App\DTOs\Responsibilities;

readonly class ChangeResponsibilityQuantityData
{
    public function __construct(public int $quantity, public string $reason, public string $idempotencyKey) {}
}
