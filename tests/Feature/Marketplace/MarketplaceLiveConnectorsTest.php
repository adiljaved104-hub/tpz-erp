<?php

namespace Tests\Feature\Marketplace;

use App\Enums\MarketplaceObservationState;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceConnectionCapabilityRecord;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductMarketplaceListing;
use App\Services\Marketplace\AmazonUaeMonitorAdapter;
use App\Services\Marketplace\BrowserMarketplaceMonitorAdapter;
use App\Services\Marketplace\CarrefourMafMonitorAdapter;
use App\Services\Marketplace\MarketplaceCredentialReferenceService;
use App\Services\Marketplace\MarketplaceObservationService;
use App\Services\Marketplace\MarketplaceStockExposureService;
use App\Services\Marketplace\NoonUaeMonitorAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceLiveConnectorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_noon_documented_offer_maps_visibility_and_stock_without_inventing_featured_offer(): void
    {
        [$listing, $connection] = $this->fixture('noon_api');
        $this->noonCredentials();
        $this->assertSame('test_ref', $connection->getRawOriginal('credential_reference'));
        $this->assertTrue((bool) app(MarketplaceCredentialReferenceService::class)->credentials($connection)['enabled']);
        $this->assertNotEmpty(app(MarketplaceCredentialReferenceService::class)->credentials($connection)['key_id'], 'key_id missing');
        $this->assertNotEmpty(app(MarketplaceCredentialReferenceService::class)->credentials($connection)['project_code'], 'project_code missing');
        $this->assertNotEmpty(app(MarketplaceCredentialReferenceService::class)->credentials($connection)['private_key'], 'private_key missing');
        Http::fake([
            '*/identity/public/v1/api/login' => Http::response([], 200, ['Set-Cookie' => 'session=fake; Path=/; HttpOnly']),
            '*/offer/v1/product/*' => Http::sequence()
                ->push($this->noonOffer(true, 4))
                ->push($this->noonOffer(false, 0)),
        ]);

        $adapter = app(NoonUaeMonitorAdapter::class);
        $this->assertSame(app(MarketplaceCredentialReferenceService::class)->credentials($connection), (new \ReflectionProperty($adapter, 'references'))->getValue($adapter)->credentials($connection));
        $live = $adapter->observe($listing, $connection);
        $this->assertSame('ok', $live->sourceStatus);
        $this->assertSame(MarketplaceObservationState::Yes, $live->listingActive);
        $this->assertSame(MarketplaceObservationState::Yes, $live->stockAvailable);
        $this->assertSame(MarketplaceObservationState::Unknown, $live->featuredOfferHeld);
        app(MarketplaceObservationService::class)->record($listing, $live);
        $this->assertSame('yes', $listing->refresh()->stock_available_state);
        $this->assertDatabaseHas('marketplace_monitor_observations', ['listing_id' => $listing->id, 'stock_available_state' => 'yes']);

        $inactive = $adapter->observe($listing, $connection);
        $this->assertSame(MarketplaceObservationState::No, $inactive->listingActive);
        $this->assertSame(MarketplaceObservationState::No, $inactive->stockAvailable);
        $this->assertSame(MarketplaceObservationState::Unknown, $inactive->featuredOfferHeld);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/offer/v1/product/') && $request->hasHeader('Cookie', 'session=fake'));
    }

    public function test_noon_fails_closed_for_missing_credentials_auth_throttling_and_malformed_payload(): void
    {
        [$listing, $connection] = $this->fixture('noon_api');
        $adapter = app(NoonUaeMonitorAdapter::class);
        $this->assertSame('not_configured', $adapter->observe($listing, $connection)->sourceStatus);
        $this->noonCredentials();
        $this->assertTrue((bool) app(MarketplaceCredentialReferenceService::class)->credentials($connection)['enabled']);
        Http::swap(new Factory);
        Http::fake(['*/identity/public/v1/api/login' => Http::response([], 401)]);
        $this->assertSame('authentication_failed', $adapter->observe($listing, $connection)->sourceStatus);
        Http::swap(new Factory);
        Http::fake(['*/identity/public/v1/api/login' => Http::response([], 429)]);
        $this->assertSame('rate_limited', $adapter->observe($listing, $connection)->sourceStatus);
        Http::swap(new Factory);
        Http::fake([
            '*/identity/public/v1/api/login' => Http::response([], 200, ['Set-Cookie' => 'session=fake; Path=/']),
            '*/offer/v1/product/*' => Http::response(['offers' => 'invalid']),
        ]);
        $this->assertSame('source_error', $adapter->observe($listing, $connection)->sourceStatus);
    }

    public function test_browser_worker_requires_verified_identity_and_returns_unknown_on_selector_or_worker_failure(): void
    {
        config()->set('marketplace_monitoring.browser_worker', ['enabled' => true, 'url' => 'http://127.0.0.1:8371', 'timeout' => 5]);
        foreach (['sharafdg_browser', 'microless_browser'] as $driver) {
            [$listing, $connection] = $this->fixture($driver);
            Http::swap(new Factory);
            Http::fake(['http://127.0.0.1:8371/observe' => Http::response([
                'source' => $driver, 'identity_verified' => true, 'listing_found' => true,
                'stock_state' => 'yes', 'stock_evidence_verified' => true, 'featured_offer_state' => 'unknown', 'reason_code' => 'ok',
            ])]);
            $adapter = app(BrowserMarketplaceMonitorAdapter::class);
            $found = $adapter->observe($listing, $connection);
            $this->assertSame(MarketplaceObservationState::Yes, $found->listingActive);
            $this->assertSame(MarketplaceObservationState::Yes, $found->stockAvailable);
            Http::assertSent(fn ($request): bool => $request['read_only'] === true && $request['candidates'][0]['strategy'] === 'direct_url');

            Http::swap(new Factory);
            Http::fake(['http://127.0.0.1:8371/observe' => Http::response([
                'source' => $driver, 'listing_found' => true, 'identity_verified' => true,
                'stock_state' => 'no', 'stock_evidence_verified' => true,
                'featured_offer_state' => 'no', 'featured_evidence_verified' => false, 'reason_code' => 'ok',
            ])]);
            $outOfStock = $adapter->observe($listing, $connection);
            $this->assertSame(MarketplaceObservationState::No, $outOfStock->stockAvailable);
            $this->assertSame(MarketplaceObservationState::Unknown, $outOfStock->featuredOfferHeld);

            Http::swap(new Factory);
            Http::fake(['http://127.0.0.1:8371/observe' => Http::response([
                'source' => $driver, 'listing_found' => false, 'verified_absence' => true,
                'stock_state' => 'unknown', 'featured_offer_state' => 'unknown', 'reason_code' => 'ok',
            ])]);
            $absent = $adapter->observe($listing, $connection);
            $this->assertSame(MarketplaceObservationState::No, $absent->listingActive);
            $this->assertSame(MarketplaceObservationState::Unknown, $absent->featuredOfferHeld);

            Http::swap(new Factory);
            Http::fake(['http://127.0.0.1:8371/observe' => Http::response(['source' => $driver, 'identity_verified' => false, 'listing_found' => true, 'stock_state' => 'no', 'featured_offer_state' => 'no', 'reason_code' => 'ok'])]);
            $this->assertSame('selector_mismatch', $adapter->observe($listing, $connection)->sourceStatus);
            Http::swap(new Factory);
            Http::fake(['http://127.0.0.1:8371/observe' => Http::response([], 503)]);
            $this->assertSame('browser_worker_unavailable', $adapter->observe($listing, $connection)->sourceStatus);
        }
    }

    public function test_carrefour_stays_unknown_until_exact_documented_contract_is_verified(): void
    {
        [$listing, $connection] = $this->fixture('carrefour_maf_api');
        Http::preventStrayRequests();
        $observation = app(CarrefourMafMonitorAdapter::class)->observe($listing, $connection);
        $this->assertSame('not_configured', $observation->sourceStatus);
        $this->assertSame(MarketplaceObservationState::Unknown, $observation->listingActive);
        $this->assertSame(MarketplaceObservationState::Unknown, $observation->featuredOfferHeld);
        Http::assertNothingSent();
    }

    public function test_reported_external_out_of_stock_is_not_counted_as_exposed_inventory(): void
    {
        [$listing] = $this->fixture('noon_api');
        $listing->forceFill(['listing_active_state' => 'yes', 'stock_available_state' => 'no'])->save();
        $service = app(MarketplaceStockExposureService::class);
        $this->assertSame(0, $service->companyBackedListingCount($listing->product_id));
        $listing->forceFill(['stock_available_state' => 'unknown'])->save();
        $this->assertSame(1, $service->companyBackedListingCount($listing->product_id));
    }

    public function test_amazon_throttling_and_malformed_offers_remain_unknown(): void
    {
        [$listing, $connection] = $this->fixture('amazon_sp_api');
        config()->set('marketplace_credentials.references.test_ref', [
            'enabled' => true, 'endpoint' => 'https://sellingpartnerapi-eu.amazon.com',
            'marketplace_id' => 'A2VIGQ35RCS4UG', 'seller_id' => 'SELLER',
            'lwa_client_id' => 'fake-client', 'lwa_client_secret' => 'fake-secret', 'refresh_token' => 'fake-refresh',
        ]);
        Cache::flush();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake-token']),
            'https://sellingpartnerapi-eu.amazon.com/*' => Http::sequence()->push([], 429)->push(['payload' => ['Offers' => 'invalid']]),
        ]);
        $adapter = app(AmazonUaeMonitorAdapter::class);
        $this->assertSame('rate_limited', $adapter->observe($listing, $connection)->sourceStatus);
        $this->assertSame('source_error', $adapter->observe($listing, $connection)->sourceStatus);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/products/pricing/v0/items/')) {
                return false;
            }

            $date = $request->header('x-amz-date')[0] ?? '';
            $parsed = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $date, new \DateTimeZone('UTC'));

            return $request->hasHeader('x-amz-access-token', 'fake-token')
                && preg_match('/^\d{8}T\d{6}Z$/', $date) === 1
                && $parsed !== false
                && $parsed->format('Ymd\THis\Z') === $date
                && $request->hasHeader('user-agent', 'TPZ-ERP-Marketplace-Monitor/1.0 (Language=PHP/'.PHP_VERSION.')');
        });
    }

    /** @return array{ProductMarketplaceListing, MarketplaceConnection} */
    private function fixture(string $driver): array
    {
        $platform = MarketplacePlatform::factory()->create();
        $account = MarketplaceAccount::query()->create(['marketplace_platform_id' => $platform->id, 'name' => 'Default', 'code' => 'default', 'enabled' => true]);
        $product = Product::factory()->create();
        $listing = ProductMarketplaceListing::query()->create([
            'product_id' => $product->id, 'marketplace_platform_id' => $platform->id, 'marketplace_account_id' => $account->id,
            'marketplace_identifier' => 'PRODUCT-1', 'listing_sku' => 'SKU-1', 'listing_title' => $product->name,
            'direct_url' => 'https://example.test/product-1',
        ]);
        $connection = MarketplaceConnection::query()->create([
            'marketplace_account_id' => $account->id, 'name' => $driver, 'driver' => $driver,
            'connection_type' => str_ends_with($driver, '_browser') ? 'browser' : 'api',
            'priority' => 10, 'enabled' => true, 'credential_reference' => 'test_ref',
        ]);
        foreach (['featured_offer', 'listing_status', 'stock_status', 'direct_product_check', 'product_search'] as $capability) {
            MarketplaceConnectionCapabilityRecord::query()->create(['marketplace_connection_id' => $connection->id, 'capability' => $capability, 'enabled' => true]);
        }

        return [$listing, $connection->refresh()];
    }

    private function noonCredentials(): void
    {
        $options = ['private_key_bits' => 2048];
        $windowsConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private, null, $options);
        config()->set('marketplace_credentials.references.test_ref', ['enabled' => true, 'key_id' => 'fake-key', 'project_code' => 'fake-project', 'private_key' => $private]);
    }

    /** @return array<string, mixed> */
    private function noonOffer(bool $live, int $stock): array
    {
        return ['partner_sku' => 'SKU-1', 'sku' => 'N-1', 'title' => 'Test', 'brand' => 'Test', 'offers' => [[
            'offer_code' => 'O-1', 'country_code' => 'ae', 'business_model' => 'noon',
            'is_active' => $live, 'active_net_stock' => $stock, 'live_status' => $live, 'offer_issues' => [],
        ]]];
    }
}
