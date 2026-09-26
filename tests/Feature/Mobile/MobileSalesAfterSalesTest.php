<?php

namespace Tests\Feature\Mobile;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\ComplaintPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\SafetClaimPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\Complaint;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\DamagedStockEvent;
use App\Models\EmployeePermissionOverride;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderFulfillmentItem;
use App\Models\OrderItem;
use App\Models\SafetClaim;
use App\Models\Team;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Mobile\NotificationTarget;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MobileSalesAfterSalesTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation { responsibilityUser as foundationResponsibilityUser; }

    protected function responsibilityUser(EmployeeRole $role, ?Team $team = null): User
    {
        $user = $this->foundationResponsibilityUser($role, $team);
        $email = 'mobile-after-sales-'.$user->id.'@techpointzone.com';
        $user->forceFill(['email' => $email])->save();
        $user->employee->forceFill(['email' => $email])->save();

        return $user->refresh();
    }

    public function test_claim_actions_use_existing_workflow_and_hide_financials_from_staff(): void
    {
        $fixture = $this->fixture();
        $staff = $fixture['employee']->user;
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);
        $this->grant($staff, [
            SafetClaimPermission::View,
            SafetClaimPermission::File,
            SafetClaimPermission::UpdateStatus,
            SafetClaimPermission::Close,
        ], $fixture['owner']);
        $this->grant($outsider, [SafetClaimPermission::View], $fixture['owner']);
        $fixture['claim']->forceFill(['claimed_amount' => '100.00'])->save();

        $ownerResponse = $this->as($fixture['owner'])->getJson('/api/mobile/v1/workspace/cases/claims/'.$fixture['claim']->id)->assertOk();
        $this->assertSame('100.00', $ownerResponse->json('data.fields.claimed_amount'));
        $this->assertContains('file', collect($ownerResponse->json('data.actions'))->pluck('key'));
        $this->assertContains('correct_claimed_amount', collect($ownerResponse->json('data.actions'))->pluck('key'));

        $staffResponse = $this->as($staff)->getJson('/api/mobile/v1/workspace/cases/claims/'.$fixture['claim']->id)->assertOk();
        $this->assertArrayNotHasKey('claimed_amount', $staffResponse->json('data.fields'));
        $this->assertNotContains('correct_claimed_amount', collect($staffResponse->json('data.actions'))->pluck('key'));
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/cases/claims/'.$fixture['claim']->id)->assertNotFound();

        $base = '/api/mobile/v1/workspace/cases/claims/'.$fixture['claim']->id.'/';
        $this->as($fixture['owner'])->postJson($base.'file')->assertUnprocessable();
        $this->as($fixture['owner'])->postJson($base.'file', ['external_reference' => 'SAFE-T-EXT-1'])->assertOk()->assertJsonPath('data.status', 'filed');
        $this->as($fixture['owner'])->postJson($base.'in_review')->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->as($fixture['owner'])->postJson($base.'rejected')->assertUnprocessable();
        $this->as($fixture['owner'])->postJson($base.'rejected', ['reason' => 'Marketplace rejected evidence'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->as($fixture['owner'])->postJson($base.'closed')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->as($fixture['owner'])->postJson($base.'in_review')->assertUnprocessable();
    }

    public function test_complaint_actions_are_scoped_and_invalid_transitions_are_rejected(): void
    {
        $fixture = $this->fixture();
        $staff = $fixture['employee']->user;
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);
        $this->grant($staff, [
            ComplaintPermission::View,
            ComplaintPermission::Update,
            ComplaintPermission::Resolve,
        ], $fixture['owner']);
        $this->grant($outsider, [ComplaintPermission::View], $fixture['owner']);

        $this->assertTrue(app(ComplaintAuthorization::class)->allows($staff, ComplaintPermission::View, $fixture['complaint']));
        $this->assertTrue(app(ComplaintAuthorization::class)->scopeQuery(Complaint::query(), $staff)->whereKey($fixture['complaint']->id)->exists());

        $detail = $this->as($staff)->getJson('/api/mobile/v1/workspace/cases/complaints/'.$fixture['complaint']->id)->assertOk();
        $this->assertSame($fixture['product']->sku, $detail->json('data.fields.product_sku'));
        $this->assertSame($fixture['order']->reference, $detail->json('data.fields.order_reference'));
        $this->assertContains('in_progress', collect($detail->json('data.actions'))->pluck('key'));
        $this->assertContains('resolved', collect($detail->json('data.actions'))->pluck('key'));
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/cases/complaints/'.$fixture['complaint']->id)->assertNotFound();

        $base = '/api/mobile/v1/workspace/cases/complaints/'.$fixture['complaint']->id.'/';
        $this->as($staff)->postJson($base.'resolved')->assertUnprocessable();
        $this->as($staff)->postJson($base.'in_progress')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->as($staff)->postJson($base.'resolved', ['resolution' => 'customer_guided', 'note' => 'Customer guided through reset'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->as($staff)->postJson($base.'closed')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->as($staff)->postJson($base.'cancelled', ['note' => 'Too late'])->assertUnprocessable();
    }

    public function test_warranty_and_internal_repair_context_actions_and_targets_remain_authorized(): void
    {
        $fixture = $this->fixture();
        $internal = WarrantyRepair::query()->create([
            'reference' => 'REP-MOBILE-001',
            'product_id' => $fixture['product']->id,
            'product_inventory_id' => $fixture['inventory']->id,
            'warehouse_id' => $fixture['inventory']->warehouse_id,
            'quantity' => 1,
            'source' => 'damaged_item',
            'issue_description' => 'Internal damaged laptop repair',
            'received_at' => now(),
            'status' => 'received',
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $fixture['owner']->id,
        ]);
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);
        $this->grant($outsider, [WarrantyRepairPermission::View], $fixture['owner']);

        $detail = $this->as($fixture['owner'])->getJson('/api/mobile/v1/workspace/warranty/'.$fixture['warranty']->id)->assertOk();
        $this->assertSame($fixture['product']->sku, $detail->json('data.fields.product_sku'));
        $this->assertSame($fixture['order']->reference, $detail->json('data.fields.order_reference'));
        $this->assertContains('under_inspection', collect($detail->json('data.actions'))->pluck('key'));
        $this->as($fixture['owner'])->postJson('/api/mobile/v1/workspace/warranty/'.$fixture['warranty']->id.'/completed')->assertUnprocessable();

        $internalList = $this->as($fixture['owner'])->getJson('/api/mobile/v1/workspace/internal-repairs')->assertOk();
        $this->assertContains($internal->id, collect($internalList->json('data'))->pluck('id'));
        $this->assertSame(
            ['module' => 'internal-repairs', 'id' => $internal->id],
            app(NotificationTarget::class)->resolve($fixture['owner'], ['target_type' => 'warranty_repair', 'target_id' => $internal->id]),
        );
        $this->assertNull(app(NotificationTarget::class)->resolve($outsider, ['target_type' => 'warranty_repair', 'target_id' => $internal->id]));
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/warranty/'.$internal->id)->assertForbidden();
    }

    public function test_claim_complaint_notification_and_global_search_targets_are_safe(): void
    {
        $fixture = $this->fixture();
        $target = app(NotificationTarget::class);

        $this->assertSame(
            ['module' => 'cases/claims', 'id' => $fixture['claim']->id],
            $target->resolve($fixture['owner'], ['target_type' => 'safet_claim', 'target_id' => $fixture['claim']->id]),
        );
        $this->assertSame(
            ['module' => 'cases/complaints', 'id' => $fixture['complaint']->id],
            $target->resolve($fixture['owner'], ['target_type' => 'complaint', 'target_id' => $fixture['complaint']->id]),
        );

        $claimSearch = $this->as($fixture['owner'])->getJson('/api/mobile/v1/workspace/search?q='.$fixture['claim']->reference)->assertOk();
        $complaintSearch = $this->as($fixture['owner'])->getJson('/api/mobile/v1/workspace/search?q='.$fixture['complaint']->reference)->assertOk();
        $this->assertSame('cases/claims', collect($claimSearch->json('data'))->flatMap(fn ($group) => $group['items'])->first()['target']['module']);
        $this->assertSame('cases/complaints', collect($complaintSearch->json('data'))->flatMap(fn ($group) => $group['items'])->first()['target']['module']);
        $this->assertStringNotContainsString('"url"', $claimSearch->getContent());
        $this->assertStringNotContainsString('"url"', $complaintSearch->getContent());
    }

    private function fixture(): array
    {
        $foundation = $this->responsibilityFoundation(5);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);
        $order = Order::query()->create([
            'reference' => 'SO-MOBILE-AFTER-001',
            'source' => 'marketplace',
            'status' => 'fulfilled',
            'warehouse_id' => $foundation['inventory']->warehouse_id,
            'marketplace_platform_id' => $foundation['platform']->id,
            'order_date' => now()->toDateString(),
            'subtotal' => 100,
            'discount_total' => 0,
            'vat_total' => 0,
            'grand_total' => 100,
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $foundation['owner']->id,
        ]);
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $foundation['product']->id,
            'product_name' => $foundation['product']->name,
            'sku' => $foundation['product']->sku,
            'ordered_quantity' => 1,
            'selling_price' => 100,
            'line_total' => 100,
        ]);
        $fulfillment = OrderFulfillment::query()->create([
            'reference' => 'SOF-MOBILE-AFTER-001',
            'order_id' => $order->id,
            'movement_group' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'fulfilled_by_user_id' => $foundation['owner']->id,
            'fulfilled_at' => now(),
        ]);
        $fulfillmentItem = OrderFulfillmentItem::query()->create([
            'order_fulfillment_id' => $fulfillment->id,
            'order_item_id' => $orderItem->id,
            'product_inventory_id' => $foundation['inventory']->id,
            'product_id' => $foundation['product']->id,
            'warehouse_id' => $foundation['inventory']->warehouse_id,
            'quantity' => 1,
            'inventory_unit_cost' => 25,
            'cogs_total' => 25,
            'posting_key' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
        $return = CustomerReturn::query()->create([
            'reference' => 'RET-MOBILE-AFTER-001',
            'order_id' => $order->id,
            'marketplace_platform_id' => $foundation['platform']->id,
            'fulfillment_warehouse_id' => $foundation['inventory']->warehouse_id,
            'receiving_warehouse_id' => $foundation['inventory']->warehouse_id,
            'status' => 'draft',
            'return_source' => 'manual',
            'reported_at' => now(),
            'created_by_user_id' => $foundation['owner']->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $returnItem = CustomerReturnItem::query()->create([
            'customer_return_id' => $return->id,
            'order_item_id' => $orderItem->id,
            'order_fulfillment_item_id' => $fulfillmentItem->id,
            'product_id' => $foundation['product']->id,
            'sku_snapshot' => $foundation['product']->sku,
            'product_name_snapshot' => $foundation['product']->name,
            'fulfilled_quantity_snapshot' => 1,
            'return_quantity' => 1,
            'inventory_unit_cost' => 25,
            'return_reason' => 'defective',
        ]);
        $damage = DamagedStockEvent::query()->create([
            'reference' => 'DMG-MOBILE-AFTER-001',
            'product_inventory_id' => $foundation['inventory']->id,
            'product_id' => $foundation['product']->id,
            'warehouse_id' => $foundation['inventory']->warehouse_id,
            'quantity' => 1,
            'source' => 'customer_return',
            'reason' => 'Damaged',
            'occurred_at' => now(),
            'reported_by_user_id' => $foundation['owner']->id,
            'status' => 'damaged',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $claim = SafetClaim::query()->create([
            'reference' => 'CLM-MOBILE-AFTER-001',
            'marketplace_platform_id' => $foundation['platform']->id,
            'customer_return_id' => $return->id,
            'customer_return_item_id' => $returnItem->id,
            'damaged_stock_event_id' => $damage->id,
            'order_id' => $order->id,
            'product_id' => $foundation['product']->id,
            'quantity' => 1,
            'source' => 'qc_damaged_customer_return',
            'status' => 'needs_filing',
            'claim_reason' => 'Damage claim',
            'currency' => 'AED',
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $foundation['owner']->id,
        ]);
        $complaint = Complaint::query()->create([
            'reference' => 'CMP-MOBILE-AFTER-001',
            'marketplace_platform_id' => $foundation['platform']->id,
            'order_id' => $order->id,
            'customer_return_id' => $return->id,
            'product_id' => $foundation['product']->id,
            'category' => 'other',
            'description' => 'Customer needs after-sales support',
            'quantity' => 1,
            'status' => 'open',
            'opened_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $foundation['owner']->id,
        ]);
        $warranty = WarrantyRepair::query()->create([
            'reference' => 'WAR-MOBILE-AFTER-001',
            'marketplace_platform_id' => $foundation['platform']->id,
            'order_id' => $order->id,
            'product_id' => $foundation['product']->id,
            'warehouse_id' => $foundation['inventory']->warehouse_id,
            'quantity' => 1,
            'source' => 'order',
            'issue_description' => 'Warranty service issue',
            'received_at' => now(),
            'status' => 'received',
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $foundation['owner']->id,
        ]);

        return $foundation + compact('order', 'return', 'claim', 'complaint', 'warranty');
    }

    private function grant(User $user, array $permissions, User $owner): void
    {
        foreach ($permissions as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $user->employee->id,
                'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow,
                'granted_by_user_id' => $owner->id,
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile sales-after-sales test')->plainTextToken);
    }
}
