<?php

namespace App\DTOs\ProductIntelligence;

use App\Enums\ProductMatchClassification;
use Illuminate\Support\Str;

final readonly class ProductMatchResult
{
    /** @param list<ProductMatchReason> $reasons */
    public function __construct(
        public int $productId,
        public string $sku,
        public string $name,
        public string $inventoryItemType,
        public int $score,
        public ProductMatchClassification $classification,
        public array $reasons,
        public ?int $sellableQuantity = null,
        public ?int $salesConfigurationId = null,
        public ?string $salesConfigurationName = null,
        public ?int $upgradeRecipeId = null,
        public ?string $buildSummary = null,
        public ?int $buildableQuantity = null,
    ) {}

    public function hasHardConflict(): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->isConflict()) {
                return true;
            }
        }

        return false;
    }

    public function compactLabel(): string
    {
        $parts = ["{$this->sku} · ".Str::limit(trim($this->name), 58), $this->classification->label()];

        $matches = collect($this->reasons)
            ->filter(fn (ProductMatchReason $reason): bool => $reason->status === 'match' && $reason->field !== 'title')
            ->take(2)
            ->pluck('message')
            ->all();
        if ($matches !== []) {
            $parts[] = implode(', ', $matches);
        }

        if ($this->salesConfigurationName !== null) {
            $parts[] = $this->salesConfigurationName;
        }
        $conflict = collect($this->reasons)->first(fn (ProductMatchReason $reason): bool => $reason->isConflict());
        if ($conflict instanceof ProductMatchReason) {
            $parts[] = $conflict->message;
        }

        if ($this->buildableQuantity !== null) {
            $parts[] = "Buildable: {$this->buildableQuantity}";
        } elseif ($this->sellableQuantity !== null) {
            $parts[] = "Sellable: {$this->sellableQuantity}";
        }

        return implode(' · ', $parts);
    }
}
