<?php

namespace App\Services\Marketplace;

use App\Enums\InventoryLocationType;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\ProductInventory;
use App\Models\ProductMarketplaceListing;
use App\Models\ResponsibilityAssignment;
use App\Services\Responsibilities\ResponsibilityAllocationService;

class MarketplaceStockExposureService
{
    public function __construct(
        private readonly MarketplaceResponsibilityResolver $responsibilities,
        private readonly ResponsibilityAllocationService $quantityAllocations,
        private readonly MarketplaceIncidentService $incidents,
    ) {}

    public function reconcile(ProductMarketplaceListing $listing): void
    {
        foreach ($this->responsibilities->assignments($listing) as $assignment) {
            $exposed = $this->companyBackedListingCount($listing->product_id, $assignment);
            $usable = $this->usableQuantity($listing->product_id, $assignment);
            $this->incidents->reconcileStockExposure($listing, $assignment, $exposed, $usable);
        }
    }

    public function companyBackedListingCount(int $productId, ?ResponsibilityAssignment $assignment = null): int
    {
        $query = ProductMarketplaceListing::query()->where('product_id', $productId)->where('monitor_enabled', true)
            ->where('listing_active_state', 'yes')
            ->where('stock_available_state', '!=', 'no')
            ->when($assignment?->platformScope !== null, fn ($listings) => $listings->where('marketplace_platform_id', $assignment->platformScope->marketplace_platform_id));

        return $query->get()->reject(fn (ProductMarketplaceListing $candidate): bool => ProductInventory::query()
            ->where('product_id', $productId)->whereRaw('available_quantity - reserved_quantity > 0')
            ->whereHas('warehouse', fn ($query) => $query->where('location_type', InventoryLocationType::MarketplaceFulfilment->value)
                ->where('marketplace_platform_id', $candidate->marketplace_platform_id))
            ->exists())->count();
    }

    public function usableQuantity(int $productId, ResponsibilityAssignment $assignment): int
    {
        $employee = $assignment->employee;
        $accountIds = InventoryAllocationAccount::query()->where('status', true)->where(function ($query) use ($employee): void {
            $query->where('employee_id', $employee->id);
            if ($employee->team_id !== null) {
                $query->orWhere('team_id', $employee->team_id);
            }
        })->where('is_system', false)->pluck('id');
        if ($accountIds->isEmpty()) {
            return 0;
        }

        $inventories = ProductInventory::query()->where('product_id', $productId)
            ->whereHas('warehouse', fn ($query) => $query->where('location_type', '!=', InventoryLocationType::MarketplaceFulfilment->value))
            ->get();

        return $inventories->sum(function (ProductInventory $inventory) use ($accountIds, $assignment): int {
            $available = (int) InventoryAllocationBalance::query()->where('product_inventory_id', $inventory->id)->whereIn('account_id', $accountIds)
                ->selectRaw('COALESCE(SUM(allocated_quantity - reserved_quantity), 0) available')->value('available');
            $available = min(max(0, $available), max(0, $inventory->sellableQuantity()));

            $quantity = ResponsibilityAssignment::query()->active()->where('employee_id', $assignment->employee_id)
                ->whereHas('quantityScope', fn ($query) => $query->where('product_inventory_id', $inventory->id))->with('quantityScope')->get();
            if ($quantity->isNotEmpty()) {
                $remaining = $quantity->sum(fn (ResponsibilityAssignment $scope): int => $this->quantityAllocations->usage($scope)['remaining']);
                $available = min($available, $remaining);
            }

            return $available;
        });
    }
}
