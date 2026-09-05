<?php

namespace App\DTOs\Returns;

final readonly class InspectCustomerReturnItemData
{
    public function __construct(
        public int $sellableQuantity,
        public int $damagedQuantity,
        public string $postingKey,
        public ?string $notes = null,
    ) {}
}
