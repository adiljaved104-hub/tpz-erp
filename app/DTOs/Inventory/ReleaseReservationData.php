<?php

namespace App\DTOs\Inventory;

final readonly class ReleaseReservationData
{
    public function __construct(
        public string $reason,
        public string $idempotencyKey,
        public ?string $movementReference = null,
    ) {}
}
