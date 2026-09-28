<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Models\ProductMarketplaceListing;

class MarketplaceMonitorManager
{
    /** @param iterable<MarketplaceMonitorAdapter> $adapters */
    public function __construct(private readonly iterable $adapters) {}

    public function observe(ProductMarketplaceListing $listing): MarketplaceObservation
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($listing)) {
                return $adapter->observe($listing);
            }
        }

        return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $listing->monitor_source, 'unsupported_source', 'No approved monitoring adapter is available for this source.');
    }
}
