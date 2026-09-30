<?php

namespace App\Services\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\MarketplaceObservationState;
use App\Models\MarketplaceConnection;
use App\Models\ProductMarketplaceListing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class NoonUaeMonitorAdapter implements MarketplaceMonitorAdapter
{
    private const BASE_URL = 'https://noon-api-gateway.noon.partners';

    public function __construct(private readonly MarketplaceCredentialReferenceService $references) {}

    public function supports(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): bool
    {
        return $connection?->driver === 'noon_api';
    }

    public function observe(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): MarketplaceObservation
    {
        $source = 'noon_api';
        $unavailable = fn (string $code) => MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $source, $code, 'Noon marketplace data is temporarily unavailable.', $listing->marketplace_account_id, $connection?->id);
        $credentials = $connection === null ? [] : $this->references->credentials($connection);
        if (! ($credentials['enabled'] ?? false) || blank($credentials['key_id'] ?? null) || blank($credentials['project_code'] ?? null) || blank($credentials['private_key'] ?? null)) {
            return $unavailable('not_configured');
        }
        $sku = trim((string) $listing->listing_sku);
        if ($sku === '') {
            return $unavailable('missing_identifier');
        }

        try {
            $jwt = $this->jwt($credentials);
            if ($jwt === null) {
                return $unavailable('authentication_failed');
            }
            $login = Http::acceptJson()->asJson()->withHeaders(['User-Agent' => 'TPZ-ERP-Marketplace-Monitor/1.0'])->timeout(12)
                ->post(self::BASE_URL.'/identity/public/v1/api/login', ['token' => $jwt, 'default_project_code' => $credentials['project_code']]);
            if (! $login->successful()) {
                return $unavailable($login->status() === 429 ? 'rate_limited' : 'authentication_failed');
            }
            $cookies = collect($login->toPsrResponse()->getHeader('Set-Cookie'))
                ->map(fn (string $cookie): string => trim(explode(';', $cookie, 2)[0]))
                ->filter(fn (string $cookie): bool => str_contains($cookie, '='))->implode('; ');
            if ($cookies === '') {
                return $unavailable('authentication_failed');
            }
            $response = Http::acceptJson()->withHeaders(['User-Agent' => 'TPZ-ERP-Marketplace-Monitor/1.0', 'Cookie' => $cookies])->timeout(12)
                ->get(self::BASE_URL.'/offer/v1/product/'.rawurlencode($sku));
            if (! $response->successful()) {
                return $unavailable(match (true) {
                    in_array($response->status(), [401, 403], true) => 'authentication_failed',
                    $response->status() === 429 => 'rate_limited',
                    default => 'api_error',
                });
            }

            $body = $response->json();
            if (! is_array($body) || ($body['partner_sku'] ?? null) !== $sku || ! is_array($body['offers'] ?? null)) {
                return $unavailable('source_error');
            }
            $offers = collect($body['offers'])->filter(fn (mixed $offer): bool => is_array($offer) && ($offer['country_code'] ?? null) === 'ae');
            if (filled($credentials['business_model'] ?? null)) {
                $offers = $offers->filter(fn (array $offer): bool => ($offer['business_model'] ?? null) === $credentials['business_model']);
            }
            if ($offers->count() !== 1) {
                return $unavailable('source_error');
            }
            $offer = $offers->first();
            if (! is_bool($offer['is_active'] ?? null) || ! is_bool($offer['live_status'] ?? null) || ! is_int($offer['active_net_stock'] ?? null) || $offer['active_net_stock'] < 0) {
                return $unavailable('source_error');
            }

            return new MarketplaceObservation(
                $listing->id, $listing->marketplace_platform_id,
                $offer['live_status'] ? MarketplaceObservationState::Yes : MarketplaceObservationState::No,
                MarketplaceObservationState::Unknown, CarbonImmutable::now(), $source, 'ok', null,
                $listing->marketplace_account_id, $connection?->id,
                $offer['active_net_stock'] > 0 ? MarketplaceObservationState::Yes : MarketplaceObservationState::No,
            );
        } catch (Throwable) {
            return $unavailable('source_error');
        }
    }

    /** @param array<string, mixed> $credentials */
    private function jwt(array $credentials): ?string
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $body = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
            .$encode(json_encode(['sub' => $credentials['key_id'], 'iat' => time(), 'jti' => (string) Str::uuid()], JSON_THROW_ON_ERROR));
        if (! openssl_sign($body, $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $body.'.'.$encode($signature);
    }
}
