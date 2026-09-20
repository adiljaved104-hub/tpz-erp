<?php

namespace App\DTOs\Responsibilities;

use App\Enums\ProductCondition;

readonly class CreateResponsibilityAssignmentBatchData
{
    /**
     * @param  array<int, int>  $scopeIds
     * @param  array<int, int>  $platformIds
     */
    public function __construct(
        public int $employeeId,
        public string $scopeType,
        public array $scopeIds,
        public ?int $categoryId,
        public ?int $platformId,
        public string $effectiveAt,
        public string $reason,
        public ?string $notes,
        public string $idempotencyKey,
        public array $platformIds = [],
        public ?int $warehouseId = null,
        public ?ProductCondition $condition = null,
    ) {}
}
