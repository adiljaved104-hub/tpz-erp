<?php

namespace App\DTOs\Warehouses;

final readonly class ChangeWarehouseStatusData
{
    public function __construct(
        public bool $active,
        public string $reason,
    ) {}
}
