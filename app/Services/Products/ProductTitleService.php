<?php

namespace App\Services\Products;

use App\Enums\ProductTitleMode;
use App\Enums\WebSalesChannel;
use App\Models\OrderItem;
use App\Models\Product;

class ProductTitleService
{
    public function accounting(Product $product): string
    {
        if (filled($product->accounting_title_override)) {
            return trim($product->accounting_title_override);
        }

        if (! $this->isLaptop($product) || ! $this->hasStructuredIdentity($product)) {
            return $this->fallback($product);
        }

        return $this->join([
            $product->displayBrandName(), $product->model, $product->processor_class ?: $product->processor,
            $product->processor_generation, $this->memoryAndStorage($product),
            $this->dedicatedGraphics($product), $product->touch_screen === true ? 'Touch' : null,
            $product->is_convertible_360 === true ? '360' : null, $product->color,
        ], $this->fallback($product));
    }

    public function website(Product $product): string
    {
        if (filled($product->website_title_override)) {
            return trim($product->website_title_override);
        }

        if (! $this->isLaptop($product) || ! $this->hasStructuredIdentity($product)) {
            return $this->fallback($product);
        }

        $lead = $this->join([$product->displayBrandName(), $product->model, $product->screen_size, 'Laptop']);
        $processor = $product->processor_model ?: $product->processor_class ?: $product->processor;
        $specifications = $this->join([
            $processor,
            filled($product->ram) ? trim($product->ram).' RAM' : null,
            filled($product->storage) ? trim($product->storage).($this->needsStorageLabel($product->storage) ? ' Storage' : '') : null,
            $this->dedicatedGraphics($product),
            $product->touch_screen === true ? 'Touch Screen' : null,
            $product->is_convertible_360 === true ? '360 Convertible' : null,
            $product->color,
        ]);

        return filled($specifications) ? "{$lead} – {$specifications}" : ($lead ?: $this->fallback($product));
    }

    public function internal(Product $product): string
    {
        return $this->fallback($product);
    }

    public function marketplace(Product $product, ?int $platformId): string
    {
        if ($platformId !== null) {
            $listings = $product->marketplaceListings->where('marketplace_platform_id', $platformId);
            if ($listings->count() === 1 && filled($listings->first()?->listing_title)) {
                return trim($listings->first()->listing_title);
            }
        }

        return $this->website($product);
    }

    public function forOrderItem(OrderItem $item, ProductTitleMode $mode = ProductTitleMode::Auto): string
    {
        $item->loadMissing(['product.marketplaceListings', 'order', 'upgradeSelection']);
        $product = $item->product;
        if (! $product) {
            return $item->customerDescription();
        }

        $resolved = match ($mode) {
            ProductTitleMode::Marketplace => $this->marketplace($product, $item->order?->marketplace_platform_id),
            ProductTitleMode::Website => $this->website($product),
            ProductTitleMode::Accounting => $this->accounting($product),
            ProductTitleMode::Custom => $item->customerDescription(),
            ProductTitleMode::Auto => $this->automatic($item, $product),
        };

        $configuration = $item->upgradeSelection?->description();

        return $configuration === null ? $resolved : $resolved."\n".$configuration;
    }

    private function automatic(OrderItem $item, Product $product): string
    {
        $order = $item->order;
        if ($order?->marketplace_platform_id !== null) {
            return $this->marketplace($product, $order->marketplace_platform_id);
        }
        if ($order?->web_sales_channel instanceof WebSalesChannel) {
            return $this->website($product);
        }

        return $order === null ? $this->internal($product) : $this->accounting($product);
    }

    private function isLaptop(Product $product): bool
    {
        $category = mb_strtolower($product->relationLoaded('categoryRelation')
            ? $product->displayCategoryName()
            : (string) $product->category);

        return str_contains($category, 'laptop') || str_contains($category, 'notebook');
    }

    private function fallback(Product $product): string
    {
        return trim($product->name) ?: trim($product->sku) ?: 'Product';
    }

    private function hasStructuredIdentity(Product $product): bool
    {
        return collect([$product->model, $product->processor_class, $product->processor_model, $product->processor_generation, $product->processor])
            ->contains(fn ($value): bool => filled($value));
    }

    private function memoryAndStorage(Product $product): ?string
    {
        $parts = array_values(array_filter([trim((string) $product->ram), trim((string) $product->storage)]));

        return $parts === [] ? null : implode('/', $parts);
    }

    private function dedicatedGraphics(Product $product): ?string
    {
        $graphics = trim((string) $product->graphics);
        if ($graphics === '' || preg_match('/\b(integrated|shared|iris|uhd)\b/i', $graphics)) {
            return null;
        }

        return $graphics;
    }

    private function needsStorageLabel(string $storage): bool
    {
        return preg_match('/\b(ssd|nvme|hdd|emmc|storage)\b/i', $storage) !== 1;
    }

    /** @param array<int, mixed> $parts */
    private function join(array $parts, string $fallback = ''): string
    {
        $title = collect($parts)->filter(fn ($part): bool => filled($part))->map(fn ($part): string => trim((string) $part))->implode(' ');

        return $title !== '' ? (preg_replace('/\s+/', ' ', $title) ?? $title) : $fallback;
    }
}
