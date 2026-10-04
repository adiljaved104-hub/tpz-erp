<?php

namespace App\DTOs\Responsibilities;

use App\Enums\ProductCondition;

readonly class ChangeResponsibilityScopeData
{
    public function __construct(
        public int $employeeId,
        public ?int $brandId,
        public ?int $productId,
        public ?int $categoryId,
        public ?ProductCondition $condition,
        public ?int $warehouseId,
        public ?int $platformId,
        public bool $assignStockByDefault,
        public string $reason,
        public string $idempotencyKey,
    ) {}
}
