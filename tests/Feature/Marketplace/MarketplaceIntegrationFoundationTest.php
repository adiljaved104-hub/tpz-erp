<?php

namespace Tests\Feature\Marketplace;

use App\Contracts\MarketplaceMonitorAdapter;
use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\EmployeeRole;
use App\Enums\MarketplaceConnectionCapability;
use App\Enums\MarketplaceConnectionType;
use App\Enums\MarketplaceObservationState;
use App\Enums\ProductCondition;
use App\Filament\Pages\MarketplaceOperations;
use App\Jobs\SendMobilePush;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceConnectionCapabilityRecord;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductMarketplaceListing;
use App\Services\Marketplace\MarketplaceConnectionCapabilityResolver;
use App\Services\Marketplace\MarketplaceListingLocator;
use App\Services\Marketplace\MarketplaceMonitoringService;
use App\Services\Marketplace\MarketplaceMonitoringSettingsService;
use App\Services\Marketplace\MarketplaceMonitorManager;
use App\Services\Marketplace\MarketplaceNewOrderService;
use App\Services\Marketplace\MarketplaceResponsibilityResolver;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MarketplaceIntegrationFoundationTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_accounts_allow_overlapping_listing_identity_but_prevent_duplicates_within_one_account(): void
    {
        $platform = MarketplacePlatform::factory()->create();
        $new = $this->account($platform, 'New', 'new');
        $renewed = $this->account($platform, 'Renewed', 'renewed');
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        $this->listing($first, $new, 'SHARED-ID', 'SHARED-SKU');
        $this->listing($second, $renewed, 'SHARED-ID', 'SHARED-SKU');
        $this->assertDatabaseCount('product_marketplace_listings', 2);

        try {
            $this->listing(Product::factory()->create(), $new, 'SHARED-ID', 'OTHER-SKU');
            $this->fail('The same marketplace identifier must be unique within an account.');
        } catch (QueryException) {
            $this->assertDatabaseCount('product_marketplace_listings', 2);
        }
    }

    public function test_connection_capability_priority_falls_back_and_all_failures_remain_unknown(): void
    {
        $platform = MarketplacePlatform::factory()->create();
        $account = $this->account($platform, 'New', 'new');
        $listing = $this->listing(Product::factory()->create(), $account);
        $this->connection($account, 'Primary Browser', MarketplaceConnectionType::Browser, 'primary_unknown', 10);
        $this->connection($account, 'Fallback Feed', MarketplaceConnectionType::Feed, 'fallback_yes', 20);
        $adapter = new FoundationFakeAdapter;
        $manager = new MarketplaceMonitorManager([$adapter], app(MarketplaceConnectionCapabilityResolver::class));

        $result = $manager->observe($listing);
        $this->assertSame(MarketplaceObservationState::Yes, $result->featuredOfferHeld);
        $this->assertSame(['primary_unknown', 'fallback_yes'], $adapter->drivers);

        MarketplaceConnection::query()->where('driver', 'fallback_yes')->update(['driver' => 'fallback_unknown']);
        $adapter->drivers = [];
        $result = $manager->observe($listing->refresh());
        $this->assertSame(MarketplaceObservationState::Unknown, $result->featuredOfferHeld);
        $this->assertSame(['primary_unknown', 'fallback_unknown'], $adapter->drivers);
    }

    public function test_browser_location_candidates_use_direct_url_before_search(): void
    {
        $platform = MarketplacePlatform::factory()->create();
        $account = $this->account($platform, 'New', 'new');
        $listing = $this->listing(Product::factory()->create(), $account);
        $connection = $this->connection($account, 'Browser', MarketplaceConnectionType::Browser, 'future_browser', 10);
        MarketplaceConnectionCapabilityRecord::query()->insert([
            ['marketplace_connection_id' => $connection->id, 'capability' => MarketplaceConnectionCapability::DirectProductCheck->value, 'enabled' => true],
            ['marketplace_connection_id' => $connection->id, 'capability' => MarketplaceConnectionCapability::ProductSearch->value, 'enabled' => true],
        ]);

        $candidates = app(MarketplaceListingLocator::class)->candidates($listing, $connection);
        $this->assertSame('direct_url', $candidates[0]['strategy']);
        $this->assertSame('product_search', $candidates[1]['strategy']);
    }

    public function test_exact_condition_responsibility_wins_and_blank_condition_is_fallback(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $general = $this->responsibilityUser(EmployeeRole::Staff);
        $renewedHandler = $this->responsibilityUser(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name, 'condition' => ProductCondition::New]);
        $renewed = $this->account($platform, 'Renewed', 'renewed');
        $new = $this->account($platform, 'New', 'new');
        $foundation = ['brand' => $brand, 'platform' => $platform, 'product' => $product, 'inventory' => null];

        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation + ['employee' => $general->employee], overrides: ['platformId' => $platform->id]), $owner);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation + ['employee' => $renewedHandler->employee], overrides: ['platformId' => $platform->id, 'condition' => ProductCondition::Renewed]), $owner);

        $resolver = app(MarketplaceResponsibilityResolver::class);
        $this->assertSame([$renewedHandler->employee->id], $resolver->assignments($this->listing($product, $renewed, 'R-1', 'R-SKU'))->pluck('employee_id')->all());
        $this->assertSame([$general->employee->id], $resolver->assignments($this->listing($product, $new, 'N-1', 'N-SKU'))->pluck('employee_id')->all());
    }

    public function test_settings_have_three_defaults_and_owner_can_change_summary_and_escalation_configuration(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $service = app(MarketplaceMonitoringSettingsService::class);
        $this->assertSame(['09:00', '14:00', '19:00'], $service->effective()['summary_times']);
        $this->assertSame(120, $service->effective()['escalation_threshold_minutes']);
        $this->assertSame(['in_app', 'email', 'push'], $service->effective()['event_channels']);
        $this->assertSame(['in_app', 'email', 'push'], $service->effective()['escalation_channels']);
        $this->assertSame(['email'], $service->effective()['summary_channels']);

        $service->save(array_replace($service->effective(), [
            'summary_times' => ['08:30', '17:15'],
            'employee_reminder_minutes' => 60,
            'escalation_threshold_minutes' => 180,
            'escalation_recipient_strategy' => 'manager_owner_admin',
            'escalation_channels' => ['in_app', 'push'],
            'event_channels' => ['email', 'push'],
            'summary_channels' => ['in_app'],
        ]), $owner);
        $effective = $service->effective();
        $this->assertSame(['08:30', '17:15'], $effective['summary_times']);
        $this->assertSame(180, $effective['escalation_threshold_minutes']);
        $this->assertSame(['in_app', 'push'], $effective['escalation_channels']);
        $this->assertSame(['email', 'push'], $effective['event_channels']);
        $this->assertSame(['in_app'], $effective['summary_channels']);
        CarbonImmutable::setTestNow('2026-09-28 08:30:00');
        try {
            $this->assertTrue($service->summaryDueNow());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_summary_channels_are_independent_and_push_uses_existing_mobile_pipeline(): void
    {
        Queue::fake();
        config()->set('mobile.push_enabled', true);
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $settings = app(MarketplaceMonitoringSettingsService::class);
        $settings->save(array_replace($settings->effective(), [
            'event_channels' => ['email'],
            'escalation_channels' => ['in_app'],
            'summary_channels' => ['in_app', 'push'],
        ]), $owner);

        $this->artisan('marketplace:send-summary')->assertSuccessful();
        $this->artisan('marketplace:send-summary')->assertSuccessful();

        $this->assertSame(1, $owner->notifications()->where('type', 'marketplace.daily_summary')->count());
        $this->assertSame(1, $admin->notifications()->where('type', 'marketplace.daily_summary')->count());
        Queue::assertPushed(SendMobilePush::class, 2);
        Queue::assertNotPushed(SendQueuedNotifications::class);
    }

    public function test_owner_connection_ui_uses_friendly_labels_without_exposing_credential_reference(): void
    {
        config()->set('marketplace_credentials.references.amazon-production-secret-reference', [
            'enabled' => true, 'endpoint' => 'https://example.test', 'marketplace_id' => 'fake-market',
            'seller_id' => 'fake-seller', 'lwa_client_id' => 'fake-client',
            'lwa_client_secret' => 'fake-secret', 'refresh_token' => 'fake-refresh',
        ]);
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $platform = MarketplacePlatform::factory()->create();
        $account = $this->account($platform, 'New', 'new');
        MarketplaceConnection::query()->create([
            'marketplace_account_id' => $account->id,
            'name' => 'Amazon Production',
            'connection_type' => MarketplaceConnectionType::Api,
            'driver' => 'amazon_sp_api',
            'priority' => 10,
            'enabled' => true,
            'health_status' => 'healthy',
            'credential_reference' => 'amazon-production-secret-reference',
            'last_health_checked_at' => now(),
        ]);

        $this->actingAs($owner)->get(MarketplaceOperations::getUrl())
            ->assertOk()
            ->assertSee('Connection method')
            ->assertSee('Amazon SP-API')
            ->assertSee('Primary')
            ->assertSee('Credentials')
            ->assertSee('Configured')
            ->assertSee('Last health check')
            ->assertDontSee('amazon-production-secret-reference')
            ->assertDontSee('Credential reference');
    }

    public function test_owner_can_disable_monitoring_from_marketplace_operations_settings(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);

        $this->actingAs($owner);
        Livewire::test(MarketplaceOperations::class)
            ->assertSet('settings.monitoring_enabled', true)
            ->set('settings.monitoring_enabled', false)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertFalse(app(MarketplaceMonitoringSettingsService::class)->effective()['monitoring_enabled']);
        $this->assertSame(['checked' => 0, 'failed' => 0, 'skipped' => 1], app(MarketplaceMonitoringService::class)->run());
    }

    public function test_push_event_channel_uses_existing_mobile_push_delivery_pipeline(): void
    {
        Queue::fake();
        config()->set('mobile.push_enabled', true);
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $responsible = $this->responsibilityUser(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        $account = $this->account($platform, 'New', 'new');
        $listing = $this->listing($product, $account);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData([
            'employee' => $responsible->employee, 'brand' => $brand, 'platform' => $platform, 'product' => $product, 'inventory' => null,
        ], overrides: ['platformId' => $platform->id]), $owner);
        $settings = app(MarketplaceMonitoringSettingsService::class);
        $settings->save(array_replace($settings->effective(), ['event_channels' => ['push']]), $owner);

        app(MarketplaceNewOrderService::class)->record($account, 'PUSH-ORDER-1', [['listing_id' => $listing->id, 'quantity' => 1]], 'api');

        $notification = $responsible->notifications()->where('type', 'marketplace.new_order')->sole();
        $this->assertTrue($notification->data['mobile_push_enabled']);
        Queue::assertPushed(SendMobilePush::class, fn (SendMobilePush $job): bool => $job->userId === $responsible->id && $job->notificationId === $notification->id);
    }

    public function test_new_order_is_deduplicated_by_account_and_routes_by_responsibility(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $responsible = $this->responsibilityUser(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        $account = $this->account($platform, 'New', 'new');
        $listing = $this->listing($product, $account);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData([
            'employee' => $responsible->employee, 'brand' => $brand, 'platform' => $platform, 'product' => $product, 'inventory' => null,
        ], overrides: ['platformId' => $platform->id]), $owner);

        $service = app(MarketplaceNewOrderService::class);
        $first = $service->record($account, 'AMZ-ORDER-1', [['listing_id' => $listing->id, 'quantity' => 2]], 'api');
        $second = $service->record($account, 'AMZ-ORDER-1', [['listing_id' => $listing->id, 'quantity' => 2]], 'webhook');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('marketplace_order_events', 1);
        $this->assertDatabaseCount('marketplace_order_event_items', 1);
        $this->assertSame(1, $responsible->notifications()->where('type', 'marketplace.new_order')->count());
        $this->assertSame(0, $owner->notifications()->where('type', 'marketplace.new_order')->count());
    }

    private function account(MarketplacePlatform $platform, string $name, string $condition): MarketplaceAccount
    {
        return MarketplaceAccount::query()->create(['marketplace_platform_id' => $platform->id, 'name' => $name, 'code' => mb_strtolower($name), 'product_condition' => $condition, 'enabled' => true]);
    }

    private function listing(Product $product, MarketplaceAccount $account, string $identifier = 'LISTING-1', string $sku = 'SKU-1'): ProductMarketplaceListing
    {
        return ProductMarketplaceListing::query()->create(['product_id' => $product->id, 'marketplace_platform_id' => $account->marketplace_platform_id, 'marketplace_account_id' => $account->id, 'marketplace_identifier' => $identifier, 'listing_sku' => $sku, 'listing_title' => $product->name, 'direct_url' => 'https://example.test/listing/'.$identifier]);
    }

    private function connection(MarketplaceAccount $account, string $name, MarketplaceConnectionType $type, string $driver, int $priority): MarketplaceConnection
    {
        $connection = MarketplaceConnection::query()->create(['marketplace_account_id' => $account->id, 'name' => $name, 'connection_type' => $type, 'driver' => $driver, 'priority' => $priority, 'enabled' => true]);
        MarketplaceConnectionCapabilityRecord::query()->create(['marketplace_connection_id' => $connection->id, 'capability' => MarketplaceConnectionCapability::FeaturedOffer, 'enabled' => true]);

        return $connection;
    }
}

class FoundationFakeAdapter implements MarketplaceMonitorAdapter
{
    /** @var array<int, string> */
    public array $drivers = [];

    public function supports(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): bool
    {
        return $connection !== null;
    }

    public function observe(ProductMarketplaceListing $listing, ?MarketplaceConnection $connection = null): MarketplaceObservation
    {
        $this->drivers[] = $connection->driver;
        $state = $connection->driver === 'fallback_yes' ? MarketplaceObservationState::Yes : MarketplaceObservationState::Unknown;

        return new MarketplaceObservation($listing->id, $listing->marketplace_platform_id, MarketplaceObservationState::Yes, $state, CarbonImmutable::now(), $connection->driver, $state === MarketplaceObservationState::Unknown ? 'source_failure' : 'ok', null, $listing->marketplace_account_id, $connection->id);
    }
}
