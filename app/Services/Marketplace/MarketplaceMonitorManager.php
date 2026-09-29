<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\MarketplaceConnectionCapability;
use App\Enums\MarketplaceObservationState;
use App\Models\ProductMarketplaceListing;

class MarketplaceMonitorManager
{
    /** @param iterable<MarketplaceMonitorAdapter> $adapters */
    public function __construct(private readonly iterable $adapters, private readonly MarketplaceConnectionCapabilityResolver $capabilities) {}

    public function observe(ProductMarketplaceListing $listing): MarketplaceObservation
    {
        $listing->loadMissing('account');
        if ($listing->account !== null) {
            $lastUnknown = null;
            foreach ($this->capabilities->connections($listing->account, MarketplaceConnectionCapability::FeaturedOffer) as $connection) {
                foreach ($this->adapters as $adapter) {
                    if (! $adapter->supports($listing, $connection)) {
                        continue;
                    }
                    $observation = $adapter->observe($listing, $connection);
                    $connection->forceFill([
                        'health_status' => $observation->featuredOfferHeld === MarketplaceObservationState::Unknown ? 'unavailable' : 'healthy',
                        'last_health_checked_at' => now(),
                        'last_healthy_at' => $observation->featuredOfferHeld === MarketplaceObservationState::Unknown ? $connection->last_healthy_at : now(),
                    ])->save();
                    if ($observation->featuredOfferHeld !== MarketplaceObservationState::Unknown) {
                        return $observation;
                    }
                    $lastUnknown = $observation;
                    break;
                }
            }
            if ($lastUnknown instanceof MarketplaceObservation) {
                return $lastUnknown;
            }
        }

        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($listing, null)) {
                return $adapter->observe($listing, null);
            }
        }

        return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $listing->monitor_source, 'unsupported_source', 'No approved monitoring connection is available for this capability.', $listing->marketplace_account_id);
    }
}
