<?php

namespace App\DTOs\Products;

use App\Enums\ProductStatus;

final readonly class ChangeProductStatusData
{
    public function __construct(
        public ProductStatus|string $status,
        public string $reason,
    ) {}
}
