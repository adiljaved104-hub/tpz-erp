<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceOperationIncident;
use App\Models\ProductMarketplaceListing;

class MarketplaceOperationsSummaryService
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'listings_monitored' => ProductMarketplaceListing::query()->where('monitor_enabled', true)->count(),
            'latest_successful_check' => ProductMarketplaceListing::query()->max('last_successful_observation_at'),
            'featured_offer_held' => ProductMarketplaceListing::query()->where('monitor_enabled', true)->where('featured_offer_state', 'yes')->count(),
            'featured_offer_lost' => ProductMarketplaceListing::query()->where('monitor_enabled', true)->where('featured_offer_state', 'no')->count(),
            'featured_offer_regained' => MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST)->whereNotNull('regained_at')->where('regained_at', '>=', now()->subDay())->count(),
            'active_stock_exposure' => MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::STOCK_EXPOSURE)->whereNull('resolved_at')->count(),
            'unacknowledged' => MarketplaceOperationIncident::query()->whereNull('resolved_at')->whereHas('recipients', fn ($query) => $query->where('recipient_role', 'primary')->whereNull('acknowledged_at'))->count(),
            'escalated' => MarketplaceOperationIncident::query()->whereNull('resolved_at')->whereNotNull('escalated_at')->count(),
            'source_failures' => ProductMarketplaceListing::query()->where('monitor_enabled', true)->whereNotNull('last_check_error')->count(),
            'breakdown' => MarketplaceOperationIncident::query()->whereNull('resolved_at')->join('products', 'products.id', '=', 'marketplace_operation_incidents.product_id')->leftJoin('product_brands', 'product_brands.id', '=', 'products.brand_id')->join('marketplace_platforms', 'marketplace_platforms.id', '=', 'marketplace_operation_incidents.marketplace_platform_id')->leftJoin('employees', 'employees.id', '=', 'marketplace_operation_incidents.responsible_employee_id')->leftJoin('teams', 'teams.id', '=', 'marketplace_operation_incidents.responsible_team_id')->select(['marketplace_operation_incidents.incident_type', 'products.sku', 'products.name as product', 'product_brands.name as brand', 'marketplace_platforms.name as platform', 'employees.name as responsible', 'teams.name as team', 'marketplace_operation_incidents.opened_at', 'marketplace_operation_incidents.escalated_at'])->orderByDesc('marketplace_operation_incidents.opened_at')->limit(100)->get(),
        ];
    }
}
