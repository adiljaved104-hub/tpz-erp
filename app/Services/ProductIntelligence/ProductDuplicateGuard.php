<?php

namespace App\Services\ProductIntelligence;

use App\DTOs\ProductIntelligence\ProductMatchReason;
use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\DTOs\ProductIntelligence\ProductMatchResult;
use App\Enums\ProductMatchClassification;
use App\Enums\ProductMatchContext;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductDuplicateGuard
{
    public function __construct(
        private readonly ProductMatchService $matcher,
        private readonly ProductAuthorization $authorization,
    ) {}

    /** @param array<string, mixed> $attributes @return Collection<int, \App\DTOs\ProductIntelligence\ProductMatchResult> */
    public function candidates(User $actor, array $attributes, ?int $excludeProductId = null): Collection
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if (mb_strlen($name) < 2) {
            return collect();
        }

        $exactTitleMatches = $this->normalizedTitleMatches($name, $excludeProductId)
            ->filter(fn (Product $product): bool => $this->sameStructuredVariant($product, $attributes));
        $visibleExactMatches = $this->visibleProducts($actor, $exactTitleMatches)
            ->map(fn (Product $product): ProductMatchResult => new ProductMatchResult(
                productId: $product->id,
                sku: $product->sku,
                name: $product->name,
                inventoryItemType: (string) ($product->inventory_item_type?->value ?? $product->inventory_item_type ?? 'product'),
                score: 100,
                classification: ProductMatchClassification::PossibleDuplicate,
                reasons: [new ProductMatchReason('title', 'match', 'The normalized product title matches; review model, specifications, and condition before continuing.')],
            ))
            ->keyBy('productId');

        $brand = filled($attributes['brand_id'] ?? null)
            ? ProductBrand::query()->whereKey((int) $attributes['brand_id'])->value('name')
            : null;
        $identityFacts = collect([
            $attributes['model'] ?? null,
            $attributes['processor'] ?? null,
            $attributes['processor_model'] ?? null,
            $attributes['ram'] ?? null,
            $attributes['storage'] ?? null,
            $attributes['graphics'] ?? null,
        ])->filter(fn ($value): bool => filled($value))->count();
        if ($brand === null || $identityFacts < 1) {
            return $visibleExactMatches->values();
        }
        $matchAttributes = [
            'brand' => $brand,
            'model' => $attributes['model'] ?? null,
            'processor' => $attributes['processor'] ?? null,
            'processor_class' => $attributes['processor_class'] ?? null,
            'processor_model' => $attributes['processor_model'] ?? null,
            'processor_generation' => $attributes['processor_generation'] ?? null,
            'ram' => $attributes['ram'] ?? null,
            'storage' => $attributes['storage'] ?? null,
            'screen_size' => $attributes['screen_size'] ?? null,
            'graphics' => $attributes['graphics'] ?? null,
            'color' => $attributes['color'] ?? null,
            'touch_screen' => $attributes['touch_screen'] ?? null,
            'is_convertible_360' => $attributes['is_convertible_360'] ?? null,
            'condition' => $attributes['condition'] ?? null,
        ];

        $matches = $this->matcher->match(new ProductMatchRequest(
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

        $products = Product::query()->whereIn('id', $matches->pluck('productId'))->get()->keyBy('id');

        $structuredMatches = $matches
            ->filter(fn ($result): bool => $this->sameStructuredVariant($products->get($result->productId), $attributes))
            ->keyBy('productId');

        return $visibleExactMatches->union($structuredMatches)->values();
    }

    /** @param array<string, mixed> $attributes @return Collection<int, \App\DTOs\ProductIntelligence\ProductMatchResult> */
    public function validateContinuation(User $actor, array $attributes, ?int $excludeProductId = null, bool $confirmed = false): Collection
    {
        $candidates = $this->candidates($actor, $attributes, $excludeProductId);
        $hiddenExactMatch = $this->hasUnreviewableExactTitle($actor, (string) ($attributes['name'] ?? ''), $excludeProductId);
        if (($candidates->isNotEmpty() || $hiddenExactMatch)
            && (! $confirmed || blank($attributes['duplicate_override_reason'] ?? null))) {
            throw ValidationException::withMessages([
                'duplicate_override_reason' => $hiddenExactMatch && $candidates->isEmpty()
                    ? 'An exact title match exists outside your Product visibility scope. Ask an authorized reviewer to inspect it, then confirm and explain why a separate Product is required.'
                    : 'Review the matching Products, confirm Save Anyway, and explain why a separate Product should be kept.',
            ]);
        }

        return $candidates;
    }

    /** @param array<string, mixed> $attributes */
    public function requiresReview(User $actor, array $attributes, ?int $excludeProductId = null): bool
    {
        return $this->candidates($actor, $attributes, $excludeProductId)->isNotEmpty()
            || $this->hasUnreviewableExactTitle($actor, (string) ($attributes['name'] ?? ''), $excludeProductId);
    }

    public function hasUnreviewableExactTitle(User $actor, string $name, ?int $excludeProductId = null): bool
    {
        $matches = $this->normalizedTitleMatches($name, $excludeProductId);
        if ($matches->isEmpty()) {
            return false;
        }

        $visibleIds = $this->visibleProducts($actor, $matches)->pluck('id')->all();

        return $matches->contains(fn (Product $product): bool => ! in_array($product->id, $visibleIds, true));
    }

    /** @param Collection<int, Product> $products @return Collection<int, Product> */
    private function visibleProducts(User $actor, Collection $products): Collection
    {
        if ($products->isEmpty() || ! $this->authorization->allows($actor, ProductPermission::View)) {
            return collect();
        }

        $query = Product::query()->whereIn('id', $products->pluck('id'));
        $this->authorization->applyVisibilityScope($query, $actor);

        return $query->get()->filter(fn (Product $product): bool => $this->authorization->allows($actor, ProductPermission::View, $product))
            ->keyBy('id');
    }

    /** @return Collection<int, Product> */
    private function normalizedTitleMatches(string $name, ?int $excludeProductId): Collection
    {
        $normalized = $this->normalizeTitle($name);
        $tokens = collect(preg_split('/[^\pL\pN]+/u', trim($name)) ?: [])
            ->map(fn (string $token): string => mb_strtolower($token))
            ->filter(fn (string $token): bool => mb_strlen($token) > 0)
            ->unique()->values();
        if ($normalized === '' || $tokens->isEmpty()) {
            return collect();
        }
        $patterns = $tokens->map(fn (string $token): string => '%'.implode('%', mb_str_split($token)).'%');

        $matches = collect();
        Product::query()->select(['id', 'name', 'sku', 'inventory_item_type'])
            ->when($excludeProductId !== null, fn (Builder $query) => $query->whereKeyNot($excludeProductId))
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    // Match character order so punctuation inserted inside a word does not hide an exact normalized title.
                    $query->whereRaw('LOWER(name) LIKE ?', [$pattern]);
                }
            })
            ->orderBy('id')->chunkById(250, function (Collection $products) use (&$matches, $normalized): void {
                foreach ($products as $product) {
                    if ($this->normalizeTitle((string) $product->name) === $normalized) {
                        $matches->push($product);
                    }
                }
            });

        return $matches;
    }

    private function normalizeTitle(string $title): string
    {
        $lower = mb_strtolower(trim($title));
        $normalized = preg_replace('/[\p{P}\p{Z}\s]+/u', '', $lower);

        return $normalized ?? Str::of($lower)->replaceMatches('/[^a-z0-9]+/i', '')->toString();
    }

    /** @param array<string, mixed> $attributes */
    private function sameStructuredVariant(?Product $product, array $attributes): bool
    {
        if (! $product) {
            return false;
        }

        foreach (['model', 'processor_model', 'ram', 'storage', 'graphics', 'screen_size', 'color'] as $field) {
            if (filled($attributes[$field] ?? null)
                && mb_strtolower(trim((string) $attributes[$field])) !== mb_strtolower(trim((string) $product->{$field}))) {
                return false;
            }
        }
        foreach (['touch_screen', 'is_convertible_360'] as $field) {
            if (($attributes[$field] ?? null) !== null && (bool) $attributes[$field] !== (bool) $product->{$field}) {
                return false;
            }
        }
        if (filled($attributes['condition'] ?? null) && (string) $attributes['condition'] !== $product->condition?->value) {
            return false;
        }

        return true;
    }
}
