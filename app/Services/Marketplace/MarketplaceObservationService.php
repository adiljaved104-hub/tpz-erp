<?php

namespace App\Services\Marketplace;

use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\MarketplaceObservationState;
use App\Models\MarketplaceMonitorObservation;
use App\Models\ProductMarketplaceListing;
use Illuminate\Support\Facades\DB;

class MarketplaceObservationService
{
    public function __construct(private readonly MarketplaceIncidentService $incidents, private readonly MarketplaceStockExposureService $exposure) {}

    public function record(ProductMarketplaceListing $listing, MarketplaceObservation $observation): void
    {
        DB::transaction(function () use ($listing, $observation): void {
            $locked = ProductMarketplaceListing::query()->lockForUpdate()->findOrFail($listing->id);
            $previousFeatured = $locked->featured_offer_state;
            MarketplaceMonitorObservation::query()->create([
                'listing_id' => $locked->id, 'source' => $observation->source,
                'marketplace_account_id' => $observation->accountId ?? $locked->marketplace_account_id,
                'marketplace_connection_id' => $observation->connectionId,
                'listing_active_state' => $observation->listingActive->value, 'featured_offer_state' => $observation->featuredOfferHeld->value,
                'observed_at' => $observation->observedAt, 'source_status' => $observation->sourceStatus,
                'safe_error' => $this->safe($observation->safeError),
            ]);
            $successful = $observation->listingActive !== MarketplaceObservationState::Unknown || $observation->featuredOfferHeld !== MarketplaceObservationState::Unknown;
            $locked->forceFill([
                'last_checked_at' => now(), 'last_successful_observation_at' => $successful ? $observation->observedAt : $locked->last_successful_observation_at,
                'last_check_status' => $observation->sourceStatus ?? ($successful ? 'ok' : 'unavailable'),
                'last_check_error' => $this->safe($observation->safeError),
                'listing_active_state' => $observation->listingActive === MarketplaceObservationState::Unknown ? $locked->listing_active_state : $observation->listingActive->value,
                'featured_offer_state' => $observation->featuredOfferHeld === MarketplaceObservationState::Unknown ? $locked->featured_offer_state : $observation->featuredOfferHeld->value,
            ])->save();

            if ($observation->featuredOfferHeld === MarketplaceObservationState::No && $previousFeatured === MarketplaceObservationState::Yes->value) {
                $this->incidents->openFeaturedOfferLost($locked);
            } elseif ($observation->featuredOfferHeld === MarketplaceObservationState::Yes && $previousFeatured === MarketplaceObservationState::No->value) {
                $this->incidents->resolveFeaturedOffer($locked);
            }
        });

        if ($observation->listingActive !== MarketplaceObservationState::Unknown) {
            $this->exposure->reconcile($listing->refresh());
        }
    }

    private function safe(?string $message): ?string
    {
        return $message === null ? null : mb_substr(preg_replace('/(token|password|secret|cookie|authorization)\s*[:=]\s*\S+/i', '$1=[redacted]', $message) ?? 'Monitoring source unavailable.', 0, 500);
    }
}
