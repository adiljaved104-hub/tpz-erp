<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\MarketplaceObservationState;
use App\Models\ProductMarketplaceListing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class AmazonUaeMonitorAdapter implements MarketplaceMonitorAdapter
{
    public function supports(ProductMarketplaceListing $listing): bool
    {
        return $listing->monitor_source === 'amazon_api';
    }

    public function observe(ProductMarketplaceListing $listing): MarketplaceObservation
    {
        if (! $this->configured()) {
            return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, 'amazon_api', 'not_configured', 'Amazon UAE monitoring is not configured.');
        }

        if (blank($listing->marketplace_identifier)) {
            return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, 'amazon_api', 'missing_identifier', 'The marketplace listing identifier is unavailable.');
        }

        try {
            $accessToken = $this->accessToken();
            if ($accessToken === null) {
                return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, 'amazon_api', 'authentication_failed', 'Amazon authorization is temporarily unavailable.');
            }

            $response = Http::acceptJson()
                ->withHeaders(['x-amz-access-token' => $accessToken])
                ->timeout(15)
                ->get(
                    rtrim((string) config('marketplace_monitoring.amazon.endpoint'), '/').'/products/pricing/v0/items/'.rawurlencode((string) $listing->marketplace_identifier).'/offers',
                    ['MarketplaceId' => config('marketplace_monitoring.amazon.marketplace_id'), 'ItemCondition' => 'New', 'CustomerType' => 'Consumer'],
                );

            if (! $response->successful()) {
                return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, 'amazon_api', 'api_error', 'Amazon marketplace data is temporarily unavailable.');
            }

            $offers = data_get($response->json(), 'payload.Offers', []);
            if (! is_array($offers)) {
                $offers = [];
            }
            $winnerSellerIds = collect($offers)
                ->filter(fn (mixed $offer): bool => is_array($offer) && (bool) ($offer['IsBuyBoxWinner'] ?? false))
                ->map(fn (array $offer): string => trim((string) ($offer['SellerId'] ?? '')))
                ->filter()
                ->values();

            $featured = $winnerSellerIds->isEmpty()
                ? MarketplaceObservationState::Unknown
                : ($winnerSellerIds->contains((string) config('marketplace_monitoring.amazon.seller_id'))
                    ? MarketplaceObservationState::Yes
                    : MarketplaceObservationState::No);

            return new MarketplaceObservation(
                $listing->id,
                $listing->marketplace_platform_id,
                MarketplaceObservationState::Yes,
                $featured,
                CarbonImmutable::now(),
                'amazon_api',
                'ok',
            );
        } catch (Throwable) {
            return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, 'amazon_api', 'source_error', 'Amazon marketplace monitoring failed safely.');
        }
    }

    private function configured(): bool
    {
        return (bool) config('marketplace_monitoring.amazon.enabled')
            && collect(['endpoint', 'marketplace_id', 'seller_id', 'lwa_client_id', 'lwa_client_secret', 'refresh_token'])
                ->every(fn (string $key): bool => filled(config("marketplace_monitoring.amazon.{$key}")));
    }

    private function accessToken(): ?string
    {
        $cacheKey = 'marketplace-monitoring:amazon:lwa:'.hash('sha256', (string) config('marketplace_monitoring.amazon.lwa_client_id').':'.(string) config('marketplace_monitoring.amazon.seller_id'));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(10)->post((string) config('marketplace_monitoring.amazon.lwa_endpoint'), [
            'grant_type' => 'refresh_token',
            'refresh_token' => config('marketplace_monitoring.amazon.refresh_token'),
            'client_id' => config('marketplace_monitoring.amazon.lwa_client_id'),
            'client_secret' => config('marketplace_monitoring.amazon.lwa_client_secret'),
        ]);
        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        $token = (string) $response->json('access_token');
        Cache::put($cacheKey, $token, now()->addSeconds(max(1, (int) $response->json('expires_in', 3600) - 60)));

        return $token;
    }
}
