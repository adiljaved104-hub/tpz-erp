<?php

namespace App\Services\StockTransfers;

use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderResponsibilityScopeService;

class StockTransferResponsibilityScopeService
{
    public function __construct(private readonly OrderResponsibilityScopeService $orders) {}

    public function requiresScope(User $user): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Manager, EmployeeRole::Staff], true);
    }

    /** @param iterable<int> $productIds */
    public function canAccessProducts(User $user, iterable $productIds, Warehouse $source, Warehouse $destination): bool
    {
        if (! $this->requiresScope($user)) {
            return true;
        }
        $platformId = $destination->location_type === InventoryLocationType::MarketplaceFulfilment
            ? $destination->marketplace_platform_id
            : ($source->location_type === InventoryLocationType::MarketplaceFulfilment ? $source->marketplace_platform_id : null);

        foreach ($productIds as $productId) {
            if (! $this->orders->canAccessProduct($user, (int) $productId, $platformId, $source->id)) {
                return false;
            }
        }

        return true;
    }

    public function canAccessTransfer(User $user, StockTransfer $transfer): bool
    {
        $transfer->loadMissing(['sourceWarehouse', 'destinationWarehouse']);

        return $this->canAccessProducts($user, $transfer->items()->pluck('product_id'), $transfer->sourceWarehouse, $transfer->destinationWarehouse);
    }
}
