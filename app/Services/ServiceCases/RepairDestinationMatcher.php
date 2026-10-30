<?php

namespace App\Services\ServiceCases;

use App\Enums\InventoryItemType;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class RepairDestinationMatcher
{
    private const TEXT_FIELDS = [
        'model', 'processor', 'processor_class', 'processor_model', 'processor_generation',
        'ram', 'storage', 'screen_size', 'graphics', 'color',
    ];

    public function candidates(Product $source, ProductCondition $condition): Builder
    {
        return Product::query()->products()->active()
            ->where('condition', $condition->value)
            ->where('brand_id', $source->brand_id)
            ->where('category_id', $source->category_id)
            ->when(filled($source->model), fn (Builder $query) => $query->where('model', $source->model))
            ->orderBy('sku');
    }

    public function matches(Product $source, Product $destination, ProductCondition $condition): bool
    {
        if ($destination->condition !== $condition
            || $destination->status !== ProductStatus::Active
            || $destination->inventory_item_type !== InventoryItemType::Product
            || ($destination->id === $source->id && $condition !== ProductCondition::New)) {
            return false;
        }
        if ($source->brand_id !== $destination->brand_id || $source->category_id !== $destination->category_id) {
            return false;
        }
        if ($this->normalized($source->brand) !== $this->normalized($destination->brand)
            || $this->normalized($source->category) !== $this->normalized($destination->category)) {
            return false;
        }
        // A model or a meaningful specification is needed to avoid matching unrelated blank catalog records.
        if (blank($source->model) && blank($source->processor_model) && blank($source->ram) && blank($source->storage)) {
            return false;
        }
        foreach (self::TEXT_FIELDS as $field) {
            if ($this->normalized($source->{$field}) !== $this->normalized($destination->{$field})) {
                return false;
            }
        }

        $sourceProfile = $source->hardwareProfile()->with('slots')->first();
        $destinationProfile = $destination->hardwareProfile()->with('slots')->first();
        if (($sourceProfile === null) !== ($destinationProfile === null)) {
            return false;
        }
        if ($sourceProfile !== null) {
            foreach (['ram_upgradeable', 'max_supported_ram_mb', 'storage_upgradeable'] as $field) {
                if ($sourceProfile->{$field} !== $destinationProfile->{$field}) {
                    return false;
                }
            }
            $slots = fn ($profile): array => $profile->slots
                ->map(fn ($slot): array => [
                    $slot->subsystem?->value, $slot->slot_key, $slot->interface_type,
                    (bool) $slot->is_soldered, (bool) $slot->is_occupied,
                    $slot->base_capacity_value, $slot->base_capacity_unit,
                ])->sortBy(fn (array $slot): string => json_encode($slot))->values()->all();
            if ($slots($sourceProfile) !== $slots($destinationProfile)) {
                return false;
            }
        }

        return (bool) $source->touch_screen === (bool) $destination->touch_screen
            && (bool) $source->is_convertible_360 === (bool) $destination->is_convertible_360;
    }

    private function normalized(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value ?? '') ?? ''));
    }
}
