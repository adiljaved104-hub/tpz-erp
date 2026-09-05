<?php

namespace App\Services\Orders;

use App\Enums\InventoryLocationType;
use App\Models\MarketplacePlatform;
use App\Models\Warehouse;
use App\Services\DefaultWarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class OrderFulfillmentLocationService
{
    public function __construct(private readonly DefaultWarehouseService $defaults) {}

    /** @return array<int, string> */
    public function options(?int $platformId): array
    {
        return $this->selectableQuery($platformId)
            ->when(
                $platformId !== null,
                fn (Builder $query): Builder => $query->orderByRaw(
                    'CASE WHEN location_type = ? AND marketplace_platform_id = ? THEN 0 WHEN is_default = 1 THEN 1 WHEN location_type = ? THEN 2 ELSE 3 END',
                    [InventoryLocationType::MarketplaceFulfilment->value, $platformId, InventoryLocationType::CompanyWarehouse->value],
                ),
                fn (Builder $query): Builder => $query->orderByRaw(
                    'CASE WHEN is_default = 1 THEN 0 WHEN location_type = ? THEN 1 ELSE 2 END',
                    [InventoryLocationType::CompanyWarehouse->value],
                ),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Warehouse $location): array => [
                $location->id => "{$location->name} ({$location->code})",
            ])
            ->all();
    }

    public function defaultId(): int
    {
        return $this->defaults->operationalDefault()->id;
    }

    public function isSelectable(Warehouse $location, ?MarketplacePlatform $platform): bool
    {
        if (! $location->status || $location->location_type === InventoryLocationType::Transit) {
            return false;
        }

        if ($location->location_type !== InventoryLocationType::MarketplaceFulfilment) {
            return true;
        }

        return $platform !== null
            && $platform->status
            && $location->marketplace_platform_id === $platform->id;
    }

    public function assertSelectable(Warehouse $location, ?MarketplacePlatform $platform): void
    {
        if ($platform !== null && ! $platform->status) {
            throw ValidationException::withMessages([
                'marketplace_platform_id' => 'Select an active Platform.',
            ]);
        }

        if (! $this->isSelectable($location, $platform)) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'The selected Fulfilled From location is not available for this Platform.',
            ]);
        }
    }

    private function selectableQuery(?int $platformId): Builder
    {
        return Warehouse::query()
            ->active()
            ->where('location_type', '!=', InventoryLocationType::Transit->value)
            ->where(function (Builder $query) use ($platformId): void {
                $query->where('location_type', '!=', InventoryLocationType::MarketplaceFulfilment->value);

                if ($platformId !== null) {
                    $query->orWhere(function (Builder $marketplace) use ($platformId): void {
                        $marketplace
                            ->where('location_type', InventoryLocationType::MarketplaceFulfilment->value)
                            ->where('marketplace_platform_id', $platformId);
                    });
                }
            });
    }
}
