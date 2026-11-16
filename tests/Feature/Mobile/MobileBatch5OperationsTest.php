<?php

namespace Tests\Feature\Mobile;

use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOperationIncidentRecipient;
use App\Models\MarketplaceOrderEvent;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\WarrantyRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileBatch5OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_incidents_are_recipient_scoped_and_acknowledgment_is_separate_from_resolution(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $recipient = $this->user(EmployeeRole::Staff);
        $outsider = $this->user(EmployeeRole::Staff);
        [$incident, $event] = $this->marketplaceRecords($recipient);

        $this->as($recipient)->getJson('/api/mobile/v1/workspace/marketplace-operations/incidents')
            ->assertOk()->assertJsonPath('data.0.id', $incident->id);
        $this->as($recipient)->getJson('/api/mobile/v1/workspace/marketplace-operations')
            ->assertOk()->assertJsonPath('data.unacknowledged_incidents', 1)->assertJsonPath('data.oversight', false);
        $this->as($owner)->getJson('/api/mobile/v1/workspace/marketplace-operations')
            ->assertOk()->assertJsonPath('data.unacknowledged_incidents', 1)->assertJsonPath('data.oversight', true);
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/marketplace-operations/incidents/'.$incident->id)
            ->assertNotFound();

        $this->as($recipient)->postJson('/api/mobile/v1/workspace/marketplace-operations/incidents/'.$incident->id.'/acknowledge')
            ->assertOk()->assertJsonPath('data.acknowledged', true)->assertJsonPath('data.status', 'open');
        $this->as($recipient)->postJson('/api/mobile/v1/workspace/marketplace-operations/incidents/'.$incident->id.'/acknowledge')
            ->assertOk()->assertJsonPath('data.acknowledged', true);
        $this->assertNull($incident->refresh()->resolved_at);
        $this->as($recipient)->getJson('/api/mobile/v1/workspace/marketplace-operations')
            ->assertOk()->assertJsonPath('data.unacknowledged_incidents', 0);

        $this->as($owner)->getJson('/api/mobile/v1/workspace/marketplace-operations/incidents/'.$incident->id)
            ->assertOk()->assertJsonPath('data.id', $incident->id);
        $this->as($owner)->getJson('/api/mobile/v1/workspace/marketplace-operations/orders/'.$event->id)
            ->assertOk()->assertJsonPath('data.id', $event->id);

        $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => self::class,
            'data' => ['target_type' => 'marketplace_order_event', 'target_id' => $event->id],
        ]);
        $this->as($recipient)->getJson('/api/mobile/v1/workspace/marketplace-operations/orders/'.$event->id)
            ->assertOk()->assertJsonPath('data.id', $event->id);
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/marketplace-operations/orders/'.$event->id)
            ->assertNotFound();
    }

    public function test_inventory_adjustment_posts_through_domain_service_is_immutable_and_replay_is_user_bound(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $otherOwner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $inventory = ProductInventory::factory()->create([
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $account = InventoryAllocationAccount::query()->create([
            'identity_key' => 'employee:'.$owner->employee->id,
            'type' => 'employee',
            'employee_id' => $owner->employee->id,
            'name' => $owner->employee->name,
            'status' => true,
        ]);
        InventoryAllocationBalance::query()->create([
            'account_id' => $account->id,
            'product_inventory_id' => $inventory->id,
            'allocated_quantity' => 5,
            'reserved_quantity' => 0,
        ]);
        $key = (string) Str::uuid();
        $holder = $this->as($owner)->getJson('/api/mobile/v1/workspace/inventory-adjustments/options/'.$inventory->id.'?type=mark_damaged')
            ->assertOk()->json('data.holders.0.value');
        $payload = [
            'inventory_id' => $inventory->id,
            'type' => 'mark_damaged',
            'quantity' => 2,
            'reason' => 'Verified handling damage',
            'idempotency_key' => $key,
            'allocation_account_id' => $holder,
        ];

        $created = $this->as($owner)->postJson('/api/mobile/v1/workspace/inventory-adjustments', $payload)
            ->assertOk()->assertJsonPath('data.fields.available_after', 3)
            ->assertJsonPath('data.fields.damaged_after', 2);
        $adjustment = InventoryAdjustment::query()->findOrFail($created->json('data.id'));
        $this->assertSame($owner->id, $adjustment->performed_by_user_id);
        $this->assertSame(3, $inventory->refresh()->available_quantity);
        $this->assertSame(2, $inventory->damaged_quantity);
        $this->assertCount(1, $adjustment->movements);
        $this->assertSame([], $created->json('data.actions'));
        $redacted = $this->as($admin)->getJson('/api/mobile/v1/workspace/inventory-adjustments/'.$adjustment->id)->assertOk();
        $this->assertArrayNotHasKey('reference_unit_cost', $redacted->json('data.fields'));
        $this->assertArrayNotHasKey('unit_cost', $redacted->json('data.items.0'));

        $this->as($owner)->postJson('/api/mobile/v1/workspace/inventory-adjustments', $payload)
            ->assertOk()->assertJsonPath('data.id', $adjustment->id);
        $this->assertDatabaseCount('inventory_adjustments', 1);
        $this->as($otherOwner)->postJson('/api/mobile/v1/workspace/inventory-adjustments', $payload)
            ->assertUnprocessable();
        $this->assertSame(3, $inventory->refresh()->available_quantity);
    }

    public function test_damaged_inventory_repair_action_preserves_stock_and_is_idempotent_per_user(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $otherOwner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $inventory = ProductInventory::factory()->create([
            'available_quantity' => 3,
            'reserved_quantity' => 0,
            'damaged_quantity' => 2,
            'average_cost' => '100.0000',
        ]);
        $event = DamagedStockEvent::query()->create([
            'reference' => 'DMG-MOBILE-B5',
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => 2,
            'source' => DamagedStockSource::WarehouseDamage,
            'reason' => 'Screen damaged in handling',
            'occurred_at' => now(),
            'reported_by_user_id' => $owner->id,
            'status' => DamagedStockStatus::Damaged,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->as($staff)->getJson('/api/mobile/v1/workspace/damaged-inventory')->assertForbidden();
        $detail = $this->as($owner)->getJson('/api/mobile/v1/workspace/damaged-inventory/'.$event->id)
            ->assertOk()->assertJsonPath('data.id', $event->id);
        $this->assertSame('send-to-repair', $detail->json('data.actions.0.key'));
        $this->assertArrayNotHasKey('average_cost', $detail->json('data.fields'));

        $key = (string) Str::uuid();
        $payload = [
            'quantity' => 1,
            'issue_description' => 'Replace damaged screen',
            'idempotency_key' => $key,
        ];
        $created = $this->as($owner)->postJson('/api/mobile/v1/workspace/damaged-inventory/'.$event->id.'/send-to-repair', $payload)
            ->assertCreated()->assertJsonPath('data.target.module', 'internal-repairs');
        $repair = WarrantyRepair::query()->findOrFail($created->json('data.id'));
        $this->assertSame($event->id, $repair->damaged_stock_event_id);
        $this->assertSame(2, $inventory->refresh()->damaged_quantity);

        $this->as($owner)->postJson('/api/mobile/v1/workspace/damaged-inventory/'.$event->id.'/send-to-repair', $payload)
            ->assertCreated()->assertJsonPath('data.id', $repair->id);
        $this->as($otherOwner)->postJson('/api/mobile/v1/workspace/damaged-inventory/'.$event->id.'/send-to-repair', $payload)
            ->assertUnprocessable();
        $this->assertDatabaseCount('warranty_repairs', 1);
        $this->assertSame(2, $inventory->refresh()->damaged_quantity);
    }

    /** @return array{MarketplaceOperationIncident, MarketplaceOrderEvent} */
    private function marketplaceRecords(User $recipient): array
    {
        $platform = MarketplacePlatform::factory()->create();
        $account = MarketplaceAccount::query()->create([
            'marketplace_platform_id' => $platform->id,
            'name' => 'Mobile Marketplace',
            'code' => 'mobile-marketplace',
            'product_condition' => ProductCondition::New->value,
            'enabled' => true,
        ]);
        $product = Product::factory()->create();
        $incident = MarketplaceOperationIncident::query()->create([
            'incident_key' => (string) Str::uuid(),
            'product_id' => $product->id,
            'marketplace_platform_id' => $platform->id,
            'marketplace_account_id' => $account->id,
            'incident_type' => MarketplaceOperationIncident::STOCK_EXPOSURE,
            'active_key' => 'mobile-batch-5-incident',
            'exposed_listing_count' => 1,
            'usable_quantity' => 0,
            'opened_at' => now(),
        ]);
        MarketplaceOperationIncidentRecipient::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $recipient->id,
            'recipient_role' => MarketplaceOperationIncidentRecipient::PRIMARY,
            'initial_notified_at' => now(),
        ]);
        $event = MarketplaceOrderEvent::query()->create([
            'marketplace_account_id' => $account->id,
            'external_order_id' => 'MOBILE-B5-ORDER',
            'detected_at' => now(),
            'source' => 'test',
        ]);

        return [$incident, $event];
    }

    private function user(EmployeeRole $role): User
    {
        $email = 'mobile-batch5-'.Str::lower(Str::random(12)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile Batch 5 test')->plainTextToken);
    }
}
