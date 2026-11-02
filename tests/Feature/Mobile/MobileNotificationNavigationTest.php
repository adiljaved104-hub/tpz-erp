<?php

namespace Tests\Feature\Mobile;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOperationIncidentRecipient;
use App\Models\MarketplaceOrderEvent;
use App\Models\MarketplaceOrderEventItem;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\MobileOperationalNotification;
use App\Services\Mobile\NotificationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileNotificationNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_notification_opens_the_exact_authorized_inventory_without_costs(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $outsider = $this->user(EmployeeRole::Staff);
        $inventory = ProductInventory::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'available_quantity' => 7,
            'reserved_quantity' => 2,
            'average_cost' => '999.0000',
        ]);
        $data = ['target_type' => 'product_inventory', 'target_id' => $inventory->id];

        $this->assertSame(
            ['module' => 'inventory', 'id' => $inventory->id],
            app(NotificationTarget::class)->resolve($owner, $data),
        );
        $detail = $this->as($owner)->getJson('/api/mobile/v1/workspace/inventory/'.$inventory->id)->assertOk();
        $detail->assertJsonPath('data.id', $inventory->id)
            ->assertJsonPath('data.fields.available_quantity', 7)
            ->assertJsonPath('data.fields.reserved_quantity', 2)
            ->assertJsonPath('data.fields.sellable_quantity', 5);
        $this->assertStringNotContainsString('average_cost', $detail->getContent());

        $this->assertNull(app(NotificationTarget::class)->resolve($outsider, $data));
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/inventory/'.$inventory->id)->assertNotFound();
    }

    public function test_marketplace_notifications_open_exact_recipient_scoped_records(): void
    {
        $recipient = $this->user(EmployeeRole::Staff);
        $outsider = $this->user(EmployeeRole::Staff);
        $platform = MarketplacePlatform::factory()->create();
        $account = MarketplaceAccount::query()->create([
            'marketplace_platform_id' => $platform->id,
            'name' => 'Amazon UAE',
            'code' => 'amazon-uae',
            'product_condition' => 'new',
            'enabled' => true,
        ]);
        $product = Product::factory()->create();
        $incident = MarketplaceOperationIncident::query()->create([
            'incident_key' => (string) Str::uuid(),
            'product_id' => $product->id,
            'marketplace_platform_id' => $platform->id,
            'marketplace_account_id' => $account->id,
            'incident_type' => MarketplaceOperationIncident::STOCK_EXPOSURE,
            'active_key' => 'mobile-test:'.$product->id,
            'exposed_listing_count' => 3,
            'usable_quantity' => 1,
            'opened_at' => now(),
        ]);
        MarketplaceOperationIncidentRecipient::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $recipient->id,
            'recipient_role' => MarketplaceOperationIncidentRecipient::PRIMARY,
        ]);

        $incidentData = ['target_type' => 'marketplace_operation_incident', 'target_id' => $incident->id];
        $this->assertSame(
            ['module' => 'marketplace-operations/incidents', 'id' => $incident->id],
            app(NotificationTarget::class)->resolve($recipient, $incidentData),
        );
        $this->as($recipient)->getJson('/api/mobile/v1/workspace/marketplace-operations/incidents/'.$incident->id)
            ->assertOk()->assertJsonPath('data.fields.exposed_listing_count', 3);
        $this->assertNull(app(NotificationTarget::class)->resolve($outsider, $incidentData));
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/marketplace-operations/incidents/'.$incident->id)->assertNotFound();

        $event = MarketplaceOrderEvent::query()->create([
            'marketplace_account_id' => $account->id,
            'external_order_id' => 'AMZ-MOBILE-1',
            'detected_at' => now(),
            'source' => 'api',
        ]);
        MarketplaceOrderEventItem::query()->create([
            'marketplace_order_event_id' => $event->id,
            'product_id' => $product->id,
            'external_sku' => $product->sku,
            'title' => $product->name,
            'product_condition' => 'new',
            'quantity' => 2,
        ]);
        $recipient->notify(new MobileOperationalNotification('marketplace.new_order', [
            'target_type' => 'marketplace_order_event',
            'target_id' => $event->id,
            'title' => 'New marketplace order',
            'message' => 'A new order requires attention.',
        ]));
        $eventData = ['target_type' => 'marketplace_order_event', 'target_id' => $event->id];
        $this->assertSame(
            ['module' => 'marketplace-operations/orders', 'id' => $event->id],
            app(NotificationTarget::class)->resolve($recipient, $eventData),
        );
        $this->as($recipient)->getJson('/api/mobile/v1/workspace/marketplace-operations/orders/'.$event->id)
            ->assertOk()->assertJsonPath('data.fields.external_order_id', 'AMZ-MOBILE-1')
            ->assertJsonPath('data.items.0.quantity', 2);
        $this->assertNull(app(NotificationTarget::class)->resolve($outsider, $eventData));
    }

    public function test_leave_target_uses_existing_hr_scope_and_revoked_target_returns_unavailable(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $outsider = $this->user(EmployeeRole::Staff);
        $type = LeaveType::query()->firstOrFail();
        $policy = LeavePolicy::query()->firstOrFail();
        $leave = LeaveRequest::query()->create([
            'reference' => 'LR-MOBILE-001',
            'employee_id' => $staff->employee->id,
            'team_id_at_request' => $staff->employee->team_id,
            'leave_type_id' => $type->id,
            'leave_policy_id' => $policy->id,
            'from_date' => '2026-11-03',
            'to_date' => '2026-11-03',
            'requested_working_days' => '1.00',
            'status' => 'pending',
            'reason' => 'Personal leave',
            'submitted_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $data = ['target_type' => 'leave_request', 'target_id' => $leave->id];

        $this->assertSame(
            ['module' => 'hr/leave-requests', 'id' => $leave->id],
            app(NotificationTarget::class)->resolve($staff, $data),
        );
        $this->as($staff)->getJson('/api/mobile/v1/workspace/hr/leave-requests/'.$leave->id)
            ->assertOk()->assertJsonPath('data.fields.reason', 'Personal leave');
        $this->assertNull(app(NotificationTarget::class)->resolve($outsider, $data));
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/hr/leave-requests/'.$leave->id)->assertNotFound();

        $staff->notify(new MobileOperationalNotification('marketplace.stock_exposure', [
            'target_type' => 'marketplace_operation_incident',
            'target_id' => 999999,
            'title' => 'Unavailable incident',
            'message' => 'This incident no longer exists.',
        ]));
        $notification = $staff->notifications()->latest()->firstOrFail();
        $this->as($staff)->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.target', null)
            ->assertJsonPath('data.available', false);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh();
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile notification navigation test')->plainTextToken);
    }
}
