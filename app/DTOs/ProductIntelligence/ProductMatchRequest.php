<?php

namespace App\DTOs\ProductIntelligence;

use App\Enums\ProductMatchContext;
use App\Models\User;

final readonly class ProductMatchRequest
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $query,
        public ProductMatchContext $context,
        public User $user,
        public ?int $warehouseId = null,
        public ?int $platformId = null,
        public array $attributes = [],
        public int $limit = 10,
        public ?int $excludeProductId = null,
    ) {}
}
