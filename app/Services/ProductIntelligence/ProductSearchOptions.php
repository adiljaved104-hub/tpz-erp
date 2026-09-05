<?php

namespace App\Services\ProductIntelligence;

use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\Enums\ProductMatchContext;
use App\Models\User;

class ProductSearchOptions
{
    public function __construct(private readonly ProductMatchService $matcher) {}

    /** @param array<string, mixed> $attributes @return array<int, string> */
    public function search(
        string $search,
        ProductMatchContext $context,
        ?User $user,
        ?int $warehouseId = null,
        ?int $platformId = null,
        array $attributes = [],
        ?int $limit = null,
    ): array {
        if (! $user instanceof User) {
            return [];
        }

        return $this->matcher->match(new ProductMatchRequest(
            query: $search,
            context: $context,
            user: $user,
            warehouseId: $warehouseId,
            platformId: $platformId,
            attributes: $attributes,
            limit: $limit ?? (int) config('product_matching.result_limit', 12),
        ))->mapWithKeys(fn ($result): array => [$result->productId => $result->compactLabel()])->all();
    }
}
