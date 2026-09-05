<?php

namespace App\DTOs\Suppliers;

final readonly class ChangeSupplierStatusData
{
    public function __construct(
        public bool $active,
        public string $reason,
    ) {}
}
