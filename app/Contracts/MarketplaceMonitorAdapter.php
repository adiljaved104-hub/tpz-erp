<?php

namespace App\Contracts;

use App\DTOs\Marketplace\MarketplaceObservation;
use App\Models\ProductMarketplaceListing;

interface MarketplaceMonitorAdapter
{
    public function supports(ProductMarketplaceListing $listing): bool;

    public function observe(ProductMarketplaceListing $listing): MarketplaceObservation;
}
