<?php

namespace App\Services\Marketplace;

use App\Models\ProductMarketplaceListing;
use App\Models\ResponsibilityAssignment;
use Illuminate\Support\Collection;

class MarketplaceResponsibilityResolver
{
    /** @return Collection<int, ResponsibilityAssignment> */
    public function assignments(ProductMarketplaceListing $listing): Collection
    {
        $listing->loadMissing('product', 'platform', 'account');
        $product = $listing->product;
        $condition = $listing->account?->product_condition ?? $product->condition->value;

        $matches = ResponsibilityAssignment::query()->active()
            ->whereHas('employee', fn ($query) => $query->where('status', true)->whereNotNull('user_id'))
            ->where(function ($query) use ($product): void {
                $query->whereDoesntHave('productScope')->orWhereHas('productScope', fn ($scope) => $scope->where('product_id', $product->id));
            })
            ->where(function ($query) use ($product): void {
                $query->whereDoesntHave('brandScope')->orWhereHas('brandScope', fn ($scope) => $scope->where('product_brand_id', $product->brand_id));
            })
            ->where(function ($query) use ($product): void {
                $query->whereDoesntHave('categoryScope')->orWhereHas('categoryScope', fn ($scope) => $scope->where('product_category_id', $product->category_id));
            })
            ->where(function ($query) use ($listing): void {
                $query->whereDoesntHave('platformScope')->orWhereHas('platformScope', fn ($scope) => $scope->where('marketplace_platform_id', $listing->marketplace_platform_id));
            })
            ->where(function ($query) use ($condition): void {
                $query->whereDoesntHave('conditionScope')->orWhereHas('conditionScope', fn ($scope) => $scope->where('product_condition', $condition));
            })
            ->where(function ($query) use ($product): void {
                $query->whereDoesntHave('quantityScope')->orWhereHas('quantityScope.inventory', fn ($scope) => $scope->where('product_id', $product->id));
            })
            ->where(function ($query) use ($product): void {
                $query->whereDoesntHave('warehouseScope')->orWhereHas('warehouseScope.warehouse.inventories', fn ($scope) => $scope->where('product_id', $product->id));
            })
            ->where(function ($query): void {
                $query->whereHas('productScope')->orWhereHas('brandScope')->orWhereHas('categoryScope')->orWhereHas('platformScope')->orWhereHas('warehouseScope')->orWhereHas('quantityScope');
            })
            ->with(['employee.user', 'employee.team', 'platformScope', 'quantityScope'])
            ->get();

        if ($matches->contains(fn (ResponsibilityAssignment $assignment): bool => $assignment->conditionScope !== null)) {
            $matches = $matches->filter(fn (ResponsibilityAssignment $assignment): bool => $assignment->conditionScope !== null);
        } else {
            $matches = $matches->filter(fn (ResponsibilityAssignment $assignment): bool => $assignment->conditionScope === null);
        }

        return $matches
            ->sortByDesc(fn (ResponsibilityAssignment $assignment): int => collect([$assignment->productScope, $assignment->brandScope, $assignment->categoryScope, $assignment->platformScope, $assignment->warehouseScope, $assignment->quantityScope])->filter()->count())
            ->groupBy('employee_id')->map->first()->values();
    }
}
