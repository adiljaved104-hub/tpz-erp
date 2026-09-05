<?php

namespace App\Services\ProductIntelligence;

use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\Enums\ProductMatchClassification;
use App\Enums\ProductMatchContext;
use App\Models\ProductBrand;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductDuplicateGuard
{
    public function __construct(private readonly ProductMatchService $matcher) {}

    /** @param array<string, mixed> $attributes @return Collection<int, \App\DTOs\ProductIntelligence\ProductMatchResult> */
    public function candidates(User $actor, array $attributes, ?int $excludeProductId = null): Collection
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if (mb_strlen($name) < 2) {
            return collect();
        }

        $brand = filled($attributes['brand_id'] ?? null)
            ? ProductBrand::query()->whereKey((int) $attributes['brand_id'])->value('name')
            : null;
        $identityFacts = collect([
            $attributes['model'] ?? null,
            $attributes['processor'] ?? null,
            $attributes['ram'] ?? null,
            $attributes['storage'] ?? null,
            $attributes['graphics'] ?? null,
        ])->filter(fn ($value): bool => filled($value))->count();
        if ($brand === null || $identityFacts < 1) {
            return collect();
        }
        $matchAttributes = [
            'brand' => $brand,
            'model' => $attributes['model'] ?? null,
            'processor' => $attributes['processor'] ?? null,
            'ram' => $attributes['ram'] ?? null,
            'storage' => $attributes['storage'] ?? null,
            'screen_size' => $attributes['screen_size'] ?? null,
            'graphics' => $attributes['graphics'] ?? null,
        ];

        return $this->matcher->match(new ProductMatchRequest(
            query: $name,
            context: $excludeProductId === null ? ProductMatchContext::ProductCreation : ProductMatchContext::ProductUpdate,
            user: $actor,
            attributes: $matchAttributes,
            limit: 5,
            excludeProductId: $excludeProductId,
        ))->filter(fn ($result): bool => $result->score >= 82 && in_array($result->classification, [
            ProductMatchClassification::Exact,
            ProductMatchClassification::VeryHigh,
            ProductMatchClassification::PossibleDuplicate,
        ], true))->values();
    }

    /** @param array<string, mixed> $attributes @return Collection<int, \App\DTOs\ProductIntelligence\ProductMatchResult> */
    public function validateContinuation(User $actor, array $attributes, ?int $excludeProductId = null): Collection
    {
        $candidates = $this->candidates($actor, $attributes, $excludeProductId);
        if ($candidates->isNotEmpty() && blank($attributes['duplicate_override_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'duplicate_override_reason' => 'Explain why a separate Product should be kept despite the likely duplicate match.',
            ]);
        }

        return $candidates;
    }
}
