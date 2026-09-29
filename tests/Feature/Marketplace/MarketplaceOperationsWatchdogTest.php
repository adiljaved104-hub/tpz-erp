<?php

namespace Tests\Feature\Marketplace;

use App\DTOs\Marketplace\MarketplaceObservation;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceObservationState;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Pages\MarketplaceOperations;
use App\Models\ActivityLog;
use App\Models\InventoryAllocationBalance;
use App\Models\MarketplaceMonitorObservation;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOperationIncidentRecipient;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ProductMarketplaceListing;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Marketplace\AmazonUaeMonitorAdapter;
use App\Services\Marketplace\MarketplaceIncidentReminderService;
use App\Services\Marketplace\MarketplaceIncidentService;
use App\Services\Marketplace\MarketplaceMonitoringService;
use App\Services\Marketplace\MarketplaceMonitoringSettingsService;
use App\Services\Marketplace\MarketplaceObservationService;
use App\Services\Marketplace\MarketplaceStockExposureService;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Notifications\NotificationRuleService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MarketplaceOperationsWatchdogTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_featured_offer_transitions_are_deduplicated_resolved_and_unknown_is_safe(): void
    {
        $context = $this->context();
        $listing = $context['listing'];
        $listing->forceFill(['featured_offer_state' => 'yes'])->save();

        $this->observe($listing, MarketplaceObservationState::Yes, MarketplaceObservationState::No);
        $incident = MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST)->sole();
        $this->assertNull($incident->resolved_at);
        $this->assertSame(1, $context['responsible']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());

        $this->observe($listing, MarketplaceObservationState::Yes, MarketplaceObservationState::No);
        $this->assertSame(1, MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST)->count());

        $this->observe($listing, MarketplaceObservationState::Yes, MarketplaceObservationState::Yes);
        $this->assertNotNull($incident->refresh()->resolved_at);
        $this->assertNotNull($incident->regained_at);

        $this->observe($listing, MarketplaceObservationState::Unknown, MarketplaceObservationState::Unknown, 'source_failure');
        $this->assertSame(1, MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST)->count());
        $this->assertSame('yes', $listing->refresh()->featured_offer_state);
        $this->assertSame('source_failure', $listing->last_check_status);
    }

    public function test_brand_and_platform_responsibility_routes_only_to_the_matching_employee(): void
    {
        $context = $this->context();
        $unrelated = $this->responsibilityUser(EmployeeRole::Staff);
        $context['listing']->forceFill(['featured_offer_state' => 'yes'])->save();
        $this->observe($context['listing'], MarketplaceObservationState::Yes, MarketplaceObservationState::No);

        $this->assertSame(1, $context['responsible']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
        $this->assertSame(0, $unrelated->notifications()->count());
        $incident = MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST)->sole();
        $this->assertSame($context['responsible']->employee->id, $incident->responsible_employee_id);
        $this->assertSame($context['platform']->id, $incident->marketplace_platform_id);
    }

    public function test_featured_offer_notifies_every_matching_employee_once_across_overlapping_scopes(): void
    {
        $context = $this->context();
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData(
            ['employee' => $second->employee, 'brand' => $context['brand'], 'platform' => $context['platform'], 'product' => $context['product'], 'inventory' => $context['inventory']],
            overrides: ['platformId' => $context['platform']->id],
        ), $context['owner']);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData(
            ['employee' => $context['responsible']->employee, 'brand' => $context['brand'], 'platform' => $context['platform'], 'product' => $context['product'], 'inventory' => $context['inventory']],
            overrides: ['brandId' => null, 'productId' => $context['product']->id, 'platformId' => $context['platform']->id],
        ), $context['owner']);

        $context['listing']->forceFill(['featured_offer_state' => 'yes'])->save();
        $this->observe($context['listing'], MarketplaceObservationState::Yes, MarketplaceObservationState::No);

        $this->assertSame(1, $context['responsible']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
        $this->assertSame(1, $second->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
        $incident = MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST)->sole();
        $this->assertSame(2, $incident->recipients()->count());
    }

    public function test_stock_exposure_uses_only_responsible_allocation_and_excludes_reserved_and_other_employee_stock(): void
    {
        $context = $this->context(available: 12, allocateToResponsible: false);
        $listing = $context['listing'];
        $listing->forceFill(['listing_active_state' => 'yes'])->save();
        ProductMarketplaceListing::query()->create(['product_id' => $context['product']->id, 'marketplace_platform_id' => $context['platform']->id, 'marketplace_identifier' => 'ASIN-2', 'listing_sku' => 'SELLER-2', 'listing_title' => 'Second'])->forceFill(['listing_active_state' => 'yes'])->save();
        ProductMarketplaceListing::query()->create(['product_id' => $context['product']->id, 'marketplace_platform_id' => $context['platform']->id, 'marketplace_identifier' => 'ASIN-3', 'listing_sku' => 'SELLER-3', 'listing_title' => 'Third'])->forceFill(['listing_active_state' => 'yes'])->save();

        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($context['inventory'], $context['owner']);
        $account = $allocations->employeeAccount($context['responsible']->employee->id);
        $allocations->reconcile($context['inventory'], $account, 2, $context['owner'], 'Marketplace responsibility allocation');
        $balance = InventoryAllocationBalance::query()->where('account_id', $account->id)->where('product_inventory_id', $context['inventory']->id)->sole();
        $balance->forceFill(['reserved_quantity' => 1])->save();

        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $allocations->reconcile($context['inventory'], $allocations->employeeAccount($other->employee->id), 8, $context['owner'], 'Other Employee allocation');

        app(MarketplaceStockExposureService::class)->reconcile($listing);
        $incident = MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::STOCK_EXPOSURE)->sole();
        $this->assertSame(3, $incident->exposed_listing_count);
        $this->assertSame(1, $incident->usable_quantity);
        $this->assertSame($context['responsible']->employee->id, $incident->responsible_employee_id);
        $this->assertSame(1, $context['responsible']->notifications()->where('type', 'marketplace.stock_exposure')->count());
        $this->assertSame(0, $other->notifications()->count());

        $balance->forceFill(['allocated_quantity' => 4, 'reserved_quantity' => 0])->save();
        app(MarketplaceStockExposureService::class)->reconcile($listing);
        $this->assertNotNull($incident->refresh()->resolved_at);
    }

    public function test_marketplace_fulfilment_inventory_excludes_its_platform_but_company_stock_remains_monitored(): void
    {
        $context = $this->context(available: 1);
        $context['listing']->forceFill(['listing_active_state' => 'yes'])->save();
        $otherPlatform = MarketplacePlatform::factory()->create(['name' => 'Noon UAE', 'normalized_name' => 'noon uae', 'code' => 'noon_uae']);
        ProductMarketplaceListing::query()->create(['product_id' => $context['product']->id, 'marketplace_platform_id' => $otherPlatform->id, 'marketplace_identifier' => 'NOON-1', 'listing_sku' => 'NOON-SKU', 'listing_title' => 'Noon'])->forceFill(['listing_active_state' => 'yes'])->save();
        $marketplaceWarehouse = Warehouse::factory()->create(['location_type' => InventoryLocationType::MarketplaceFulfilment, 'marketplace_platform_id' => $context['platform']->id]);
        ProductInventory::factory()->create(['product_id' => $context['product']->id, 'warehouse_id' => $marketplaceWarehouse->id, 'available_quantity' => 5, 'reserved_quantity' => 0]);

        $this->assertSame(1, app(MarketplaceStockExposureService::class)->companyBackedListingCount($context['product']->id));
    }

    public function test_quantity_responsibility_caps_usable_stock_independently_of_broader_scope(): void
    {
        $context = $this->context(available: 6);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData(
            ['employee' => $context['responsible']->employee, 'brand' => $context['brand'], 'platform' => $context['platform'], 'product' => $context['product'], 'inventory' => $context['inventory']],
            ResponsibilityAssignmentMode::Quantity,
            ['assignedQuantity' => 1, 'platformId' => $context['platform']->id],
        ), $context['owner']);
        $context['listing']->forceFill(['listing_active_state' => 'yes'])->save();
        ProductMarketplaceListing::query()->create(['product_id' => $context['product']->id, 'marketplace_platform_id' => $context['platform']->id, 'marketplace_identifier' => 'ASIN-CAP', 'listing_sku' => 'SELLER-CAP', 'listing_title' => 'Capped'])->forceFill(['listing_active_state' => 'yes'])->save();

        app(MarketplaceStockExposureService::class)->reconcile($context['listing']);
        $incident = MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::STOCK_EXPOSURE)->sole();
        $this->assertSame(2, $incident->exposed_listing_count);
        $this->assertSame(1, $incident->usable_quantity);
    }

    public function test_unconfigured_adapter_records_unknown_without_false_incident_or_marketplace_mutation(): void
    {
        config()->set('marketplace_monitoring.amazon.enabled', false);
        Http::preventStrayRequests();
        $context = $this->context();
        $context['listing']->forceFill(['featured_offer_state' => 'yes', 'listing_active_state' => 'yes'])->save();

        $result = app(MarketplaceMonitoringService::class)->run(1);
        $this->assertSame(1, $result['checked']);
        $this->assertSame('yes', $context['listing']->refresh()->featured_offer_state);
        $this->assertSame('not_configured', $context['listing']->last_check_status);
        $this->assertSame('Amazon UAE monitoring is not configured.', $context['listing']->last_check_error);
        $this->assertDatabaseCount('marketplace_operation_incidents', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_amazon_adapter_refreshes_lwa_token_and_maps_yes_no_and_unknown_safely(): void
    {
        $context = $this->context();
        $this->configureAmazon();
        Cache::flush();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'short-lived-access', 'expires_in' => 3600]),
            'https://sellingpartnerapi-eu.amazon.com/*' => Http::sequence()
                ->push(['payload' => ['Offers' => [['SellerId' => 'SELLER-123', 'IsBuyBoxWinner' => true]]]])
                ->push(['payload' => ['Offers' => [['SellerId' => 'COMPETITOR', 'IsBuyBoxWinner' => true]]]])
                ->push(['payload' => ['Offers' => []]]),
        ]);

        $adapter = app(AmazonUaeMonitorAdapter::class);
        $this->assertSame(MarketplaceObservationState::Yes, $adapter->observe($context['listing'])->featuredOfferHeld);
        $this->assertSame(MarketplaceObservationState::No, $adapter->observe($context['listing'])->featuredOfferHeld);
        $this->assertSame(MarketplaceObservationState::Unknown, $adapter->observe($context['listing'])->featuredOfferHeld);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.amazon.com/auth/o2/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-secret');
    }

    public function test_amazon_auth_failure_is_unknown_and_does_not_log_or_persist_secrets(): void
    {
        $context = $this->context();
        $this->configureAmazon();
        Cache::flush();
        Log::spy();
        Http::fake(['https://api.amazon.com/auth/o2/token' => Http::response(['error_description' => 'refresh-secret client-secret'], 401)]);

        $observation = app(AmazonUaeMonitorAdapter::class)->observe($context['listing']);
        $this->assertSame(MarketplaceObservationState::Unknown, $observation->featuredOfferHeld);
        $this->assertSame('authentication_failed', $observation->sourceStatus);
        $this->assertStringNotContainsString('refresh-secret', (string) $observation->safeError);
        $this->assertStringNotContainsString('client-secret', (string) $observation->safeError);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_observations_are_immutable(): void
    {
        $context = $this->context();
        $this->observe($context['listing'], MarketplaceObservationState::Yes, MarketplaceObservationState::Yes);
        $observation = MarketplaceMonitorObservation::query()->sole();

        try {
            $observation->forceFill(['source_status' => 'changed'])->save();
            $this->fail('An observation must not be updateable.');
        } catch (\LogicException) {
            $this->assertSame('ok', $observation->refresh()->source_status);
        }

        try {
            $observation->delete();
            $this->fail('An observation must not be deleteable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('marketplace_monitor_observations', ['id' => $observation->id]);
        }
    }

    public function test_management_summary_is_owner_admin_only(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);

        $this->actingAs($owner);
        $this->assertTrue(MarketplaceOperations::canAccess());
        $this->get(MarketplaceOperations::getUrl())->assertOk()->assertSee('Marketplace Operations')->assertSee('Listings monitored');
        $this->actingAs($admin);
        $this->assertTrue(MarketplaceOperations::canAccess());
        $this->actingAs($staff);
        $this->assertFalse(MarketplaceOperations::canAccess());
        $this->get(MarketplaceOperations::getUrl())->assertForbidden();
    }

    public function test_acknowledgment_is_owned_separate_from_read_and_prevents_reminders(): void
    {
        CarbonImmutable::setTestNow('2026-09-27 08:00:00');
        try {
            $context = $this->context();
            $context['listing']->forceFill(['featured_offer_state' => 'yes'])->save();
            $this->observe($context['listing'], MarketplaceObservationState::Yes, MarketplaceObservationState::No);
            $notification = $context['responsible']->notifications()->where('type', 'marketplace.featured_offer_lost')->sole();
            app('auth')->forgetGuards();
            $token = $context['responsible']->createToken('marketplace-watchdog')->plainTextToken;

            $this->withToken($token)->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/read')->assertOk();
            $recipient = MarketplaceOperationIncidentRecipient::query()->where('user_id', $context['responsible']->id)
                ->whereHas('incident', fn ($query) => $query->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST))->sole();
            $this->assertNull($recipient->acknowledged_at);
            $this->withToken($token)->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/acknowledge')->assertOk()->assertJsonPath('data.acknowledged', true);
            $acknowledgedAt = $recipient->refresh()->acknowledged_at;
            $this->withToken($token)->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/acknowledge')->assertOk();
            $this->assertTrue($acknowledgedAt->equalTo($recipient->refresh()->acknowledged_at));
            $this->assertSame(1, ActivityLog::query()->where('event', 'marketplace_incident.acknowledged')->count());

            CarbonImmutable::setTestNow('2026-09-27 11:00:00');
            app(MarketplaceIncidentReminderService::class)->evaluate();
            $this->assertSame(1, $context['responsible']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_reminders_and_escalations_are_idempotent_and_owner_is_not_normally_notified(): void
    {
        CarbonImmutable::setTestNow('2026-09-27 08:00:00');
        try {
            $context = $this->context();
            $context['listing']->forceFill(['featured_offer_state' => 'yes'])->save();
            $this->observe($context['listing'], MarketplaceObservationState::Yes, MarketplaceObservationState::No);
            $this->assertSame(0, $context['owner']->notifications()->whereIn('type', ['marketplace.featured_offer_lost', 'marketplace.stock_exposure'])->count());

            CarbonImmutable::setTestNow('2026-09-28 09:00:00');
            app(MarketplaceIncidentReminderService::class)->evaluate();
            app(MarketplaceIncidentReminderService::class)->evaluate();
            $this->assertSame(2, $context['responsible']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
            $this->assertSame(1, $context['owner']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
            $this->assertNotNull(MarketplaceOperationIncident::query()->where('incident_type', MarketplaceOperationIncident::FEATURED_OFFER_LOST)->sole()->escalated_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_hourly_reminder_and_configurable_escalation_threshold_are_applied(): void
    {
        CarbonImmutable::setTestNow('2026-09-27 08:00:00');
        try {
            $context = $this->context();
            $settings = app(MarketplaceMonitoringSettingsService::class);
            $settings->save(array_replace($settings->effective(), ['employee_reminder_minutes' => 60, 'escalation_threshold_minutes' => 180]), $context['owner']);
            $context['listing']->forceFill(['featured_offer_state' => 'yes'])->save();
            $this->observe($context['listing'], MarketplaceObservationState::Yes, MarketplaceObservationState::No);

            CarbonImmutable::setTestNow('2026-09-27 09:00:00');
            app(MarketplaceIncidentReminderService::class)->evaluate();
            $this->assertSame(2, $context['responsible']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
            $this->assertSame(0, $context['owner']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());

            CarbonImmutable::setTestNow('2026-09-27 11:00:00');
            app(MarketplaceIncidentReminderService::class)->evaluate();
            $this->assertSame(1, $context['owner']->notifications()->where('type', 'marketplace.featured_offer_lost')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_monitor_lock_is_non_overlapping_and_monitoring_mutates_no_inventory(): void
    {
        $context = $this->context(available: 7);
        $before = $context['inventory']->only(['available_quantity', 'reserved_quantity', 'damaged_quantity']);
        $lock = Cache::lock('marketplace-operations-monitor', 60);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(['checked' => 0, 'failed' => 0, 'skipped' => 1], app(MarketplaceMonitoringService::class)->run());
        } finally {
            $lock->release();
        }
        $this->assertSame($before, $context['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity']));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_daily_summary_is_deduplicated_and_scheduler_jobs_are_bounded(): void
    {
        Queue::fake();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $email = \Mockery::mock(EmailConfigurationService::class);
        $email->shouldReceive('enabled')->andReturnTrue();
        app()->instance(EmailConfigurationService::class, $email);

        $this->assertCount(2, app(MarketplaceIncidentService::class)->escalationRecipients());
        $this->assertTrue(app(NotificationRuleService::class)->channelEnabled('marketplace.daily_summary', 'email'));

        $this->artisan('marketplace:send-summary')->assertSuccessful();
        $this->artisan('marketplace:send-summary')->assertSuccessful();
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $this->assertDatabaseCount('notification_rule_deliveries', 2);

        $events = collect(app(Schedule::class)->events());
        $monitor = $events->first(fn ($event): bool => str_contains($event->command, 'marketplace:monitor'));
        $summary = $events->first(fn ($event): bool => str_contains($event->command, 'marketplace:send-summary'));
        $this->assertNotNull($monitor);
        $this->assertNotNull($summary);
        $this->assertTrue($monitor->withoutOverlapping);
        $this->assertTrue($summary->withoutOverlapping);
    }

    /** @return array{owner:User,responsible:User,brand:ProductBrand,platform:MarketplacePlatform,product:Product,inventory:ProductInventory,listing:ProductMarketplaceListing} */
    private function context(int $available = 5, bool $allocateToResponsible = true): array
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $responsible = $this->responsibilityUser(EmployeeRole::Staff);
        $email = 'market-watch-'.$responsible->id.'@techpointzone.com';
        $responsible->forceFill(['email' => $email])->save();
        $responsible->employee->forceFill(['email' => $email])->save();
        $brand = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE', 'normalized_name' => 'amazon uae', 'code' => 'amazon_uae']);
        $product = Product::factory()->create(['name' => 'HP EliteBook', 'brand' => 'HP', 'brand_id' => $brand->id]);
        $warehouse = Warehouse::factory()->create(['location_type' => InventoryLocationType::CompanyWarehouse, 'status' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => $available, 'reserved_quantity' => 0]);
        $assignment = app(ResponsibilityAssignmentService::class)->create($this->assignmentData(['employee' => $responsible->employee, 'brand' => $brand, 'platform' => $platform, 'product' => $product, 'inventory' => $inventory], overrides: ['platformId' => $platform->id]), $owner);
        $listing = ProductMarketplaceListing::query()->create(['product_id' => $product->id, 'marketplace_platform_id' => $platform->id, 'marketplace_identifier' => 'ASIN-1', 'listing_sku' => 'SELLER-1', 'listing_title' => 'HP EliteBook']);

        if ($allocateToResponsible) {
            $allocationService = app(InventoryAllocationService::class);
            $allocationService->ensureShadowCoverage($inventory, $owner);
            $allocationService->reconcile($inventory, $allocationService->employeeAccount($responsible->employee->id), $available, $owner, 'Marketplace monitoring test allocation');
        }

        return compact('owner', 'responsible', 'brand', 'platform', 'product', 'inventory', 'listing', 'assignment');
    }

    private function observe(ProductMarketplaceListing $listing, MarketplaceObservationState $active, MarketplaceObservationState $featured, string $status = 'ok'): void
    {
        app(MarketplaceObservationService::class)->record($listing, new MarketplaceObservation($listing->id, $listing->marketplace_platform_id, $active, $featured, CarbonImmutable::now(), 'fake', $status, $status === 'source_failure' ? 'Safe source failure.' : null));
    }

    private function configureAmazon(): void
    {
        config()->set('marketplace_monitoring.amazon', [
            'enabled' => true,
            'endpoint' => 'https://sellingpartnerapi-eu.amazon.com',
            'marketplace_id' => 'A2VIGQ35RCS4UG',
            'seller_id' => 'SELLER-123',
            'lwa_client_id' => 'client-id',
            'lwa_client_secret' => 'client-secret',
            'refresh_token' => 'refresh-secret',
            'lwa_endpoint' => 'https://api.amazon.com/auth/o2/token',
        ]);
    }
}
