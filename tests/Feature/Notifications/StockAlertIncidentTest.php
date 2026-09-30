<?php

namespace Tests\Feature\Notifications;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Notifications;
use App\Models\ActivityLog;
use App\Models\InventoryAllocationBalance;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ProductMarketplaceListing;
use App\Models\StockAlertIncident;
use App\Models\StockAlertIncidentRecipient;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Observers\ProductInventoryObserver;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Notifications\StockAlertIncidentService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockAlertIncidentTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_management_inventory_alert_switch_is_per_user_and_preserves_detection(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $mutedAdmin = $this->mobileUser(EmployeeRole::Admin);
        $otherAdmin = $this->mobileUser(EmployeeRole::Admin);
        $inventory = ProductInventory::factory()->create(['available_quantity' => 5, 'reserved_quantity' => 0]);

        Livewire::actingAs($mutedAdmin)->test(Notifications::class)
            ->assertSee('Receive Inventory Alerts')
            ->set('receiveInventoryAlerts', false)
            ->call('saveInventoryAlertPreference')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('activity_logs', [
            'actor_user_id' => $mutedAdmin->id,
            'event' => 'notifications.inventory_alert_preference_changed',
        ]);

        $this->transition($inventory, 2);
        $this->assertSame(1, StockAlertIncident::query()->where('alert_type', StockAlertIncident::LOW_STOCK)->count());
        $this->assertSame(0, $mutedAdmin->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(1, $owner->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(1, $otherAdmin->notifications()->where('type', 'inventory.low_stock')->count());

        $this->transition($inventory, 0);
        $this->assertSame(1, StockAlertIncident::query()->where('alert_type', StockAlertIncident::OUT_OF_STOCK)->count());
        $this->assertSame(0, $mutedAdmin->notifications()->where('type', 'inventory.out_of_stock')->count());
        $this->assertSame(1, $owner->notifications()->where('type', 'inventory.out_of_stock')->count());
        $this->assertSame(1, $otherAdmin->notifications()->where('type', 'inventory.out_of_stock')->count());

        Livewire::actingAs($mutedAdmin)->test(Notifications::class)
            ->assertSet('receiveInventoryAlerts', false)
            ->set('receiveInventoryAlerts', true)
            ->call('saveInventoryAlertPreference')
            ->assertHasNoErrors();
        app(StockAlertIncidentService::class)->reconcile($inventory);
        $this->assertSame(1, $mutedAdmin->notifications()->where('type', 'inventory.out_of_stock')->count());
    }

    public function test_platform_responsibility_routes_main_warehouse_low_stock_without_allocation_or_duplicates(): void
    {
        $context = $this->platformContext();

        $this->assertDatabaseMissing('inventory_allocation_balances', [
            'product_inventory_id' => $context['inventory']->id,
        ]);

        $this->transition($context['inventory'], 2);
        $this->transition($context['inventory'], 1);

        $this->assertSame(1, $context['responsible']->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(0, $context['unrelated']->notifications()->count());
        $this->assertSame(1, $context['owner']->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertDatabaseHas('stock_alert_incident_recipients', [
            'user_id' => $context['responsible']->id,
            'recipient_role' => StockAlertIncidentRecipient::PRIMARY,
        ]);
    }

    public function test_platform_responsibility_routes_main_warehouse_out_of_stock(): void
    {
        $context = $this->platformContext();

        $this->transition($context['inventory'], 0);

        $this->assertSame(1, $context['responsible']->notifications()->where('type', 'inventory.out_of_stock')->count());
        $this->assertSame(0, $context['unrelated']->notifications()->count());
        $this->assertSame(1, $context['owner']->notifications()->where('type', 'inventory.out_of_stock')->count());
    }

    public function test_owner_and_admin_remain_the_fallback_when_no_responsibility_matches(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $admin = $this->mobileUser(EmployeeRole::Admin);
        $unrelated = $this->mobileUser(EmployeeRole::Manager);
        $inventory = ProductInventory::factory()->create([
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        $this->transition($inventory, 2);

        $this->assertSame(1, $owner->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(1, $admin->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(0, $unrelated->notifications()->count());
    }

    public function test_allocation_holder_without_responsibility_receives_low_and_out_of_stock_once_with_management(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $admin = $this->mobileUser(EmployeeRole::Admin);
        $holder = $this->mobileUser(EmployeeRole::Manager);
        $unrelated = $this->mobileUser(EmployeeRole::Manager);
        $inventory = ProductInventory::factory()->create(['available_quantity' => 5, 'reserved_quantity' => 0]);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $account = $allocations->employeeAccount($holder->employee->id);
        $allocations->reconcile($inventory, $account, 2, $owner, 'Alert routing allocation');

        $this->transition($inventory, 2);
        $this->assertSame(1, $holder->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(1, $owner->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(1, $admin->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(0, $unrelated->notifications()->count());

        // Preserve the current positive holder attribution while exercising OOS routing.
        $this->transition($inventory, 0);
        $this->assertSame(1, $holder->notifications()->where('type', 'inventory.out_of_stock')->count());
        $this->assertSame(1, $owner->notifications()->where('type', 'inventory.out_of_stock')->count());
        $this->assertSame(1, $admin->notifications()->where('type', 'inventory.out_of_stock')->count());
        $this->assertSame(1, StockAlertIncidentRecipient::query()
            ->whereHas('incident', fn ($query) => $query->where('alert_type', StockAlertIncident::OUT_OF_STOCK))
            ->where('user_id', $holder->id)->count());
    }

    public function test_team_allocation_notifies_only_active_authorized_team_users_and_system_is_not_a_recipient(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $team = Team::query()->create(['name' => 'Amazon Operations', 'status' => true]);
        $member = $this->mobileUser(EmployeeRole::Manager);
        $member->employee->forceFill(['team_id' => $team->id])->save();
        $unrelated = $this->mobileUser(EmployeeRole::Manager);
        $inventory = ProductInventory::factory()->create(['available_quantity' => 5, 'reserved_quantity' => 0]);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $allocations->reconcile($inventory, $allocations->teamAccount($team->id), 2, $owner, 'Team alert allocation');

        $this->transition($inventory, 2);

        $this->assertSame(1, $member->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(0, $unrelated->notifications()->count());
        $this->assertDatabaseMissing('stock_alert_incident_recipients', ['user_id' => $unrelated->id]);
        $this->assertGreaterThan(0, InventoryAllocationBalance::query()
            ->where('account_id', $allocations->systemAccount()->id)
            ->where('product_inventory_id', $inventory->id)->value('allocated_quantity'));
    }

    public function test_brand_and_product_responsibilities_keep_routing_without_duplicate_notifications(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $brand = ProductBrand::factory()->create(['name' => 'Dell', 'normalized_name' => 'dell']);
        $product = Product::factory()->create(['brand' => $brand->name, 'brand_id' => $brand->id]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]);
        $brandResponsible = $this->mobileUser(EmployeeRole::Manager);
        $productResponsible = $this->mobileUser(EmployeeRole::Manager);

        app(ResponsibilityAssignmentService::class)->create(
            $this->assignmentData(['employee' => $brandResponsible->employee, 'brand' => $brand]),
            $owner,
        );
        app(ResponsibilityAssignmentService::class)->create(
            $this->assignmentData(
                ['employee' => $productResponsible->employee, 'brand' => $brand],
                overrides: ['brandId' => null, 'productId' => $product->id],
            ),
            $owner,
        );
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($inventory, $owner);
        $allocations->reconcile(
            $inventory,
            $allocations->employeeAccount($brandResponsible->employee->id),
            1,
            $owner,
            'Deduplicated alert recipient route',
        );

        $this->transition($inventory, 2);
        $this->transition($inventory, 1);

        $this->assertSame(1, $brandResponsible->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(1, $productResponsible->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertDatabaseCount('stock_alert_incident_recipients', 3);
    }

    public function test_low_stock_crossing_creates_one_incident_for_all_responsible_employees_only(): void
    {
        $context = $this->context(2);
        $unrelated = $this->mobileUser(EmployeeRole::Manager);

        $this->transition($context['inventory'], 4);
        $this->assertDatabaseCount('stock_alert_incidents', 0);

        $this->transition($context['inventory'], 2);
        $incident = StockAlertIncident::query()->where('alert_type', StockAlertIncident::LOW_STOCK)->sole();

        $this->assertNull($incident->resolved_at);
        $this->assertSame(2, $incident->current_sellable_quantity);
        $this->assertCount(3, $incident->recipients);
        foreach ($context['responsible'] as $responsible) {
            $this->assertSame(1, $responsible->notifications()->where('type', 'inventory.low_stock')->count());
        }
        $this->assertSame(0, $unrelated->notifications()->count());
        $this->assertSame(1, $context['owner']->notifications()->where('type', 'inventory.low_stock')->count());

        $this->transition($context['inventory'], 1);
        $this->assertSame(1, StockAlertIncident::query()->where('alert_type', StockAlertIncident::LOW_STOCK)->count());
        foreach ($context['responsible'] as $responsible) {
            $this->assertSame(1, $responsible->notifications()->where('type', 'inventory.low_stock')->count());
        }
    }

    public function test_out_of_stock_is_deduplicated_resolved_and_reopened_as_a_fresh_incident(): void
    {
        $context = $this->context();

        $this->transition($context['inventory'], 2);
        $low = StockAlertIncident::query()->where('alert_type', StockAlertIncident::LOW_STOCK)->sole();

        $this->transition($context['inventory'], 0);
        $low->refresh();
        $firstOut = StockAlertIncident::query()->where('alert_type', StockAlertIncident::OUT_OF_STOCK)->sole();

        $this->assertNotNull($low->resolved_at);
        $this->assertNull($firstOut->resolved_at);

        $this->transition($context['inventory'], 0);
        $this->assertSame(1, StockAlertIncident::query()->where('alert_type', StockAlertIncident::OUT_OF_STOCK)->count());

        $this->transition($context['inventory'], 5);
        $this->assertNotNull($firstOut->refresh()->resolved_at);

        $this->transition($context['inventory'], 0);
        $secondOut = StockAlertIncident::query()
            ->where('alert_type', StockAlertIncident::OUT_OF_STOCK)
            ->latest('id')
            ->firstOrFail();

        $this->assertNotSame($firstOut->id, $secondOut->id);
        $this->assertNotSame($firstOut->incident_key, $secondOut->incident_key);
        $this->assertNull($secondOut->resolved_at);
    }

    public function test_read_and_acknowledgment_are_separate_owned_and_idempotent(): void
    {
        $context = $this->context();
        $responsible = $context['responsible'][0];
        $outsider = $this->mobileUser(EmployeeRole::Manager);
        $this->transition($context['inventory'], 0);
        $notification = $responsible->notifications()->where('type', 'inventory.out_of_stock')->sole();
        $recipient = StockAlertIncidentRecipient::query()->where('user_id', $responsible->id)->sole();

        $this->as($responsible)->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertNotNull($notification->refresh()->read_at);
        $this->assertNull($recipient->refresh()->acknowledged_at);

        $first = $this->as($responsible)
            ->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/acknowledge')
            ->assertOk()
            ->assertJsonPath('data.acknowledgment_required', true)
            ->assertJsonPath('data.acknowledged', true);
        $acknowledgedAt = $first->json('data.acknowledged_at');

        $this->as($responsible)
            ->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/acknowledge')
            ->assertOk()
            ->assertJsonPath('data.acknowledged_at', $acknowledgedAt);

        $this->as($outsider)
            ->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/acknowledge')
            ->assertNotFound();

        $this->assertSame(1, ActivityLog::query()->where('event', 'stock_alert.acknowledged')->count());
        $this->assertNull(StockAlertIncident::query()->sole()->resolved_at);
    }

    public function test_acknowledged_recipient_gets_no_later_out_of_stock_reminder(): void
    {
        CarbonImmutable::setTestNow('2026-09-24 08:00:00');
        try {
            $context = $this->context();
            $responsible = $context['responsible'][0];
            $this->transition($context['inventory'], 0);
            $notification = $responsible->notifications()->where('type', 'inventory.out_of_stock')->sole();

            $this->as($responsible)
                ->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/acknowledge')
                ->assertOk();

            CarbonImmutable::setTestNow('2026-09-24 11:00:00');
            $this->artisan('inventory:send-stock-reminders')->assertSuccessful();

            $this->assertSame(1, $responsible->notifications()->where('type', 'inventory.out_of_stock')->count());
            $this->assertSame(0, $context['owner']->notifications()->where('type', 'inventory.low_stock')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_due_out_of_stock_reminder_and_final_owner_admin_escalation_are_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-09-24 08:00:00');
        try {
            $context = $this->context();
            $responsible = $context['responsible'][0];
            $admin = $this->mobileUser(EmployeeRole::Admin);
            $unrelated = $this->mobileUser(EmployeeRole::Staff);
            $this->transition($context['inventory'], 0);

            CarbonImmutable::setTestNow('2026-09-24 10:01:00');
            $this->artisan('inventory:send-stock-reminders')->assertSuccessful();
            $this->assertSame(2, $responsible->notifications()->where('type', 'inventory.out_of_stock')->count());

            CarbonImmutable::setTestNow('2026-09-26 08:01:00');
            $this->artisan('inventory:send-stock-reminders')->assertSuccessful();
            $this->artisan('inventory:send-stock-reminders')->assertSuccessful();

            $this->assertSame(3, $responsible->notifications()->where('type', 'inventory.out_of_stock')->count());
            // Management receives the initial incident and normal reminders; the existing
            // 48-hour escalation remains a distinct, idempotent escalation event.
            $this->assertSame(4, $context['owner']->notifications()->where('type', 'inventory.out_of_stock')->count());
            $this->assertSame(4, $admin->notifications()->where('type', 'inventory.out_of_stock')->count());
            $this->assertSame(0, $unrelated->notifications()->count());
            $this->assertNotNull(StockAlertIncident::query()->sole()->escalated_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_resolved_incident_receives_no_reminder_and_mobile_list_exposes_safe_context_only_to_owner(): void
    {
        CarbonImmutable::setTestNow('2026-09-24 08:00:00');
        try {
            $context = $this->context();
            $responsible = $context['responsible'][0];
            $outsider = $this->mobileUser(EmployeeRole::Manager);
            $this->transition($context['inventory'], 0);
            $notification = $responsible->notifications()->where('type', 'inventory.out_of_stock')->sole();

            $list = $this->as($responsible)->getJson('/api/mobile/v1/workspace/notifications')->assertOk();
            $item = collect($list->json('data'))->firstWhere('id', $notification->id);
            $this->assertTrue($item['acknowledgment_required']);
            $this->assertFalse($item['acknowledged']);
            $this->assertSame('out_of_stock', $item['incident']['alert_type']);
            $this->assertArrayNotHasKey('average_cost', $item['incident']);

            $this->as($outsider)
                ->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/acknowledge')
                ->assertNotFound();

            $this->transition($context['inventory'], 5);
            CarbonImmutable::setTestNow('2026-09-26 12:00:00');
            $this->artisan('inventory:send-stock-reminders')->assertSuccessful();

            $this->assertSame(1, $responsible->notifications()->where('type', 'inventory.out_of_stock')->count());
            $this->assertNotNull(StockAlertIncident::query()->sole()->resolved_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array{owner:User,responsible:array<int,User>,inventory:ProductInventory} */
    private function context(int $responsibleCount = 1): array
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $brand = ProductBrand::factory()->create(['name' => 'Acer', 'normalized_name' => 'acer']);
        $product = Product::factory()->create([
            'sku' => 'TPZ-STOCK-'.Str::upper(Str::random(6)),
            'name' => 'Acer Aspire 5',
            'brand' => $brand->name,
            'brand_id' => $brand->id,
        ]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => Warehouse::factory(),
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]);
        $responsible = [];

        for ($i = 0; $i < $responsibleCount; $i++) {
            $manager = $this->mobileUser(EmployeeRole::Manager);
            app(ResponsibilityAssignmentService::class)->create(
                $this->assignmentData(['employee' => $manager->employee, 'brand' => $brand]),
                $owner,
            );
            $responsible[] = $manager;
        }

        return compact('owner', 'responsible', 'inventory');
    }

    /** @return array{owner:User,responsible:User,unrelated:User,inventory:ProductInventory} */
    private function platformContext(): array
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $responsible = $this->mobileUser(EmployeeRole::Manager);
        $unrelated = $this->mobileUser(EmployeeRole::Manager);
        $brand = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $platform = MarketplacePlatform::factory()->create([
            'name' => 'Amazon UAE',
            'normalized_name' => 'amazon uae',
            'code' => 'amazon_uae',
        ]);
        $product = Product::factory()->create(['brand' => $brand->name, 'brand_id' => $brand->id]);
        ProductMarketplaceListing::query()->create([
            'product_id' => $product->id,
            'marketplace_platform_id' => $platform->id,
            'marketplace_identifier' => 'ASIN-'.Str::upper(Str::random(8)),
            'listing_sku' => 'AMZ-'.Str::upper(Str::random(8)),
            'listing_title' => $product->name,
        ]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => Warehouse::factory()->create(['marketplace_platform_id' => null])->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        app(ResponsibilityAssignmentService::class)->create(
            $this->assignmentData(
                ['employee' => $responsible->employee, 'brand' => $brand],
                overrides: ['brandId' => null, 'platformId' => $platform->id],
            ),
            $owner,
        );

        return compact('owner', 'responsible', 'unrelated', 'inventory');
    }

    private function transition(ProductInventory $inventory, int $available): void
    {
        $inventory->forceFill(['available_quantity' => $available])->saveQuietly();
        app(ProductInventoryObserver::class)->updated($inventory);
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Build 7 stock alert test')->plainTextToken);
    }

    private function mobileUser(EmployeeRole $role): User
    {
        $user = $this->responsibilityUser($role);
        $email = 'build7-stock-'.$user->id.'@techpointzone.com';
        $user->forceFill(['email' => $email])->save();
        $user->employee->forceFill(['email' => $email])->save();

        return $user->refresh();
    }
}
