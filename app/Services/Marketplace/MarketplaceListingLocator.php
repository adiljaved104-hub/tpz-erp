<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceConnectionCapability;
use App\Models\MarketplaceConnection;
use App\Models\ProductMarketplaceListing;

class MarketplaceListingLocator
{
    /** @return array<int, array{strategy:string,value:string}> */
    public function candidates(ProductMarketplaceListing $listing, MarketplaceConnection $connection): array
    {
        $candidates = [];
        if ($this->supports($connection, MarketplaceConnectionCapability::DirectProductCheck) && filled($listing->direct_url)) {
            $candidates[] = ['strategy' => 'direct_url', 'value' => (string) $listing->direct_url];
        }
        if ($this->supports($connection, MarketplaceConnectionCapability::ProductSearch)) {
            $query = $listing->marketplace_identifier ?: ($listing->listing_sku ?: $listing->listing_title);
            if (filled($query)) {
                $candidates[] = ['strategy' => 'product_search', 'value' => (string) $query];
            }
        }

        return $candidates;
    }

    private function supports(MarketplaceConnection $connection, MarketplaceConnectionCapability $capability): bool
    {
        return $connection->capabilities()->where('enabled', true)->where('capability', $capability->value)->exists();
    }
}
