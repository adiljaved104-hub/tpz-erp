<?php

namespace App\DTOs\Responsibilities;

use App\Enums\ResponsibilityAssignmentMode;

readonly class CreateResponsibilityAssignmentData
{
    public function __construct(
        public int $employeeId,
        public ResponsibilityAssignmentMode $mode,
        public ?int $brandId,
        public ?int $platformId,
        public ?int $productId,
        public ?int $productInventoryId,
        public ?int $assignedQuantity,
        public string $effectiveAt,
        public string $reason,
        public ?string $notes,
        public string $idempotencyKey,
        public ?int $categoryId = null,
    ) {}
}
