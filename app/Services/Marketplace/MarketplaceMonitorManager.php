<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\MarketplaceConnectionCapability;
use App\Enums\MarketplaceObservationState;
use App\Models\ProductMarketplaceListing;
use Throwable;

class MarketplaceMonitorManager
{
    /** @param iterable<MarketplaceMonitorAdapter> $adapters */
    public function __construct(private readonly iterable $adapters, private readonly MarketplaceConnectionCapabilityResolver $capabilities) {}

    public function observe(ProductMarketplaceListing $listing): MarketplaceObservation
    {
        $listing->loadMissing('account');
        if ($listing->account !== null) {
            $lastUnknown = null;
            $connections = collect([MarketplaceConnectionCapability::FeaturedOffer, MarketplaceConnectionCapability::ListingStatus, MarketplaceConnectionCapability::StockStatus])
                ->flatMap(fn (MarketplaceConnectionCapability $capability) => $this->capabilities->connections($listing->account, $capability))
                ->unique('id')->sortBy([['priority', 'asc'], ['id', 'asc']]);
            foreach ($connections as $connection) {
                foreach ($this->adapters as $adapter) {
                    if (! $adapter->supports($listing, $connection)) {
                        continue;
                    }
                    try {
                        $observation = $adapter->observe($listing, $connection);
                    } catch (Throwable) {
                        $observation = MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $connection->driver, 'source_error', 'Marketplace source check failed safely.', $listing->marketplace_account_id, $connection->id);
                    }
                    $healthy = $observation->listingActive !== MarketplaceObservationState::Unknown
                        || $observation->stockAvailable !== MarketplaceObservationState::Unknown
                        || $observation->featuredOfferHeld !== MarketplaceObservationState::Unknown;
                    $connection->forceFill([
                        'health_status' => $healthy ? 'healthy' : 'unavailable',
                        'last_health_checked_at' => now(),
                        'last_healthy_at' => $healthy ? now() : $connection->last_healthy_at,
                    ])->save();
                    $needsFeatured = $connection->capabilities()->where('enabled', true)
                        ->where('capability', MarketplaceConnectionCapability::FeaturedOffer->value)->exists();
                    if ($healthy && (! $needsFeatured || $observation->featuredOfferHeld !== MarketplaceObservationState::Unknown)) {
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
