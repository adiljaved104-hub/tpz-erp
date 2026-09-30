<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Models\MarketplaceConnection;
use App\Models\ProductMarketplaceListing;

class CarrefourMafMonitorAdapter implements MarketplaceMonitorAdapter
{
    public function __construct(private readonly MarketplaceCredentialReferenceService $references) {}

    public function supports(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): bool
    {
        return $connection?->driver === 'carrefour_maf_api';
    }

    public function observe(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): MarketplaceObservation
    {
        $credentials = $connection === null ? [] : $this->references->credentials($connection);
        $status = ($credentials['enabled'] ?? false) ? 'unsupported_capability' : 'not_configured';

        // The Owner-supplied documentation is inaccessible from this environment.
        // Keep this boundary inert until the exact read endpoints and payloads are verified.
        return MarketplaceObservation::unavailable(
            $listing->id, $listing->marketplace_platform_id, 'carrefour_maf_api', $status,
            'Carrefour MAF read endpoint mapping is awaiting verified documentation.',
            $listing->marketplace_account_id, $connection?->id,
        );
    }
}
