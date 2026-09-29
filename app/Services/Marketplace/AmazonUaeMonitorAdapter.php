<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\MarketplaceObservationState;
use App\Models\MarketplaceConnection;
use App\Models\ProductMarketplaceListing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class AmazonUaeMonitorAdapter implements MarketplaceMonitorAdapter
{
    public function __construct(private readonly MarketplaceCredentialReferenceService $credentialReferences) {}

    public function supports(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): bool
    {
        return $connection?->driver === 'amazon_sp_api' || ($connection === null && $listing->monitor_source === 'amazon_api');
    }

    public function observe(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): MarketplaceObservation
    {
        $credentials = $connection === null ? (array) config('marketplace_monitoring.amazon', []) : $this->credentialReferences->credentials($connection);
        $source = $connection?->driver ?? 'amazon_api';
        if (! $this->configured($credentials)) {
            return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $source, 'not_configured', 'Amazon UAE monitoring is not configured.', $listing->marketplace_account_id, $connection?->id);
        }

        if (blank($listing->marketplace_identifier)) {
            return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $source, 'missing_identifier', 'The marketplace listing identifier is unavailable.', $listing->marketplace_account_id, $connection?->id);
        }

        try {
            $accessToken = $this->accessToken($credentials);
            if ($accessToken === null) {
                return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $source, 'authentication_failed', 'Amazon authorization is temporarily unavailable.', $listing->marketplace_account_id, $connection?->id);
            }

            $response = Http::acceptJson()
                ->withHeaders(['x-amz-access-token' => $accessToken])
                ->timeout(15)
                ->get(
                    rtrim((string) ($credentials['endpoint'] ?? ''), '/').'/products/pricing/v0/items/'.rawurlencode((string) $listing->marketplace_identifier).'/offers',
                    ['MarketplaceId' => $credentials['marketplace_id'] ?? null, 'ItemCondition' => $listing->account?->product_condition === 'renewed' ? 'Used' : 'New', 'CustomerType' => 'Consumer'],
                );

            if (! $response->successful()) {
                return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $source, 'api_error', 'Amazon marketplace data is temporarily unavailable.', $listing->marketplace_account_id, $connection?->id);
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
                : ($winnerSellerIds->contains((string) ($credentials['seller_id'] ?? ''))
                    ? MarketplaceObservationState::Yes
                    : MarketplaceObservationState::No);

            return new MarketplaceObservation(
                $listing->id,
                $listing->marketplace_platform_id,
                MarketplaceObservationState::Yes,
                $featured,
                CarbonImmutable::now(),
                $source,
                'ok',
                null,
                $listing->marketplace_account_id,
                $connection?->id,
            );
        } catch (Throwable) {
            return MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $source, 'source_error', 'Amazon marketplace monitoring failed safely.', $listing->marketplace_account_id, $connection?->id);
        }
    }

    /** @param array<string, mixed> $credentials */
    private function configured(array $credentials): bool
    {
        return (bool) ($credentials['enabled'] ?? false)
            && collect(['endpoint', 'marketplace_id', 'seller_id', 'lwa_client_id', 'lwa_client_secret', 'refresh_token'])
                ->every(fn (string $key): bool => filled($credentials[$key] ?? null));
    }

    /** @param array<string, mixed> $credentials */
    private function accessToken(array $credentials): ?string
    {
        $cacheKey = 'marketplace-monitoring:amazon:lwa:'.hash('sha256', (string) ($credentials['lwa_client_id'] ?? '').':'.(string) ($credentials['seller_id'] ?? ''));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(10)->post((string) ($credentials['lwa_endpoint'] ?? 'https://api.amazon.com/auth/o2/token'), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials['refresh_token'] ?? null,
            'client_id' => $credentials['lwa_client_id'] ?? null,
            'client_secret' => $credentials['lwa_client_secret'] ?? null,
        ]);
        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        $token = (string) $response->json('access_token');
        Cache::put($cacheKey, $token, now()->addSeconds(max(1, (int) $response->json('expires_in', 3600) - 60)));

        return $token;
    }
}
