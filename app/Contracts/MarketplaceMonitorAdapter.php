<?php

namespace App\Contracts;

use App\DTOs\Marketplace\MarketplaceObservation;
use App\Models\MarketplaceConnection;
use App\Models\ProductMarketplaceListing;

interface MarketplaceMonitorAdapter
{
    public function supports(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): bool;

    public function observe(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): MarketplaceObservation;
}
