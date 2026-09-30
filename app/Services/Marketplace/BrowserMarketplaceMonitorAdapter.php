<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\MarketplaceObservationState;
use App\Models\MarketplaceConnection;
use App\Models\ProductMarketplaceListing;
use Carbon\CarbonImmutable;
use Throwable;

class BrowserMarketplaceMonitorAdapter implements MarketplaceMonitorAdapter
{
    public function __construct(private readonly BrowserWorkerClient $worker, private readonly MarketplaceListingLocator $locator) {}

    public function supports(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): bool
    {
        return in_array($connection?->driver, ['sharafdg_browser', 'microless_browser'], true);
    }

    public function observe(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): MarketplaceObservation
    {
        $source = $connection?->driver ?? 'browser';
        $unavailable = fn (string $code) => MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $source, $code, 'Browser monitoring is temporarily unavailable.', $listing->marketplace_account_id, $connection?->id);
        if ($connection === null || ! config('marketplace_monitoring.browser_worker.enabled') || blank(config('marketplace_monitoring.browser_worker.url'))) {
            return $unavailable('not_configured');
        }
        $candidates = $this->locator->candidates($listing, $connection);
        if ($candidates === [] || (blank($listing->listing_sku) && blank($listing->marketplace_identifier))) {
            return $unavailable('missing_identifier');
        }

        try {
            $response = $this->worker->observe([
                'platform' => $source === 'sharafdg_browser' ? 'sharafdg' : 'microless',
                'candidates' => $candidates,
                'expected_identity' => ['sku' => $listing->listing_sku, 'identifier' => $listing->marketplace_identifier],
                'read_only' => true,
            ]);
            if ($response->status() === 429) {
                return $unavailable('rate_limited');
            }
            if (! $response->successful()) {
                return $unavailable('browser_worker_unavailable');
            }
            $data = $response->json();
            if (! is_array($data) || ($data['source'] ?? null) !== $source) {
                return $unavailable('source_error');
            }
            $reason = $data['reason_code'] ?? null;
            if (is_string($reason) && $reason !== 'ok') {
                return $unavailable(in_array($reason, ['selector_mismatch', 'browser_worker_unavailable', 'source_error', 'rate_limited'], true) ? $reason : 'source_error');
            }
            if (! is_bool($data['listing_found'] ?? null)
                || ($data['listing_found'] && ($data['identity_verified'] ?? false) !== true)
                || (! $data['listing_found'] && ($data['verified_absence'] ?? false) !== true)) {
                return $unavailable('selector_mismatch');
            }
            $listingActive = $data['listing_found'] ? MarketplaceObservationState::Yes : MarketplaceObservationState::No;
            $stock = $this->state($data['stock_state'] ?? null);
            $featured = $this->state($data['featured_offer_state'] ?? null);
            if ($stock === null || $featured === null) {
                return $unavailable('selector_mismatch');
            }
            if ($stock !== MarketplaceObservationState::Unknown && ($data['stock_evidence_verified'] ?? false) !== true) {
                $stock = MarketplaceObservationState::Unknown;
            }
            if ($featured !== MarketplaceObservationState::Unknown && ($data['featured_evidence_verified'] ?? false) !== true) {
                $featured = MarketplaceObservationState::Unknown;
            }
            if (! $data['listing_found']) {
                $stock = MarketplaceObservationState::Unknown;
                $featured = MarketplaceObservationState::Unknown;
            }

            return new MarketplaceObservation($listing->id, $listing->marketplace_platform_id, $listingActive, $featured, CarbonImmutable::now(), $source, 'ok', null, $listing->marketplace_account_id, $connection->id, $stock);
        } catch (Throwable) {
            return $unavailable('browser_worker_unavailable');
        }
    }

    private function state(mixed $value): ?MarketplaceObservationState
    {
        return is_string($value) ? MarketplaceObservationState::tryFrom($value) : null;
    }
}
