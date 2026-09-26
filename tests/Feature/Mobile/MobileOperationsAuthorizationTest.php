<?php

namespace Tests\Feature\Mobile;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Tasks\CreateTaskData;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\ProductPermission;
use App\Enums\TaskPriority;
use App\Enums\WarrantyRepairPermission;
use App\Models\CustomerReturn;
use App\Models\EmployeePermissionOverride;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\Team;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Notifications\MobileOperationalNotification;
use App\Services\Mobile\NotificationTarget;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MobileOperationsAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation { responsibilityUser as foundationResponsibilityUser; }

    protected function responsibilityUser(EmployeeRole $role, ?Team $team = null): User
    {
        $user = $this->foundationResponsibilityUser($role, $team);
        $email = 'mobile-test-'.$user->id.'@techpointzone.com';
        $user->forceFill(['email' => $email])->save();
        $user->employee->forceFill(['email' => $email])->save();

        return $user->refresh();
    }

    private function as(string $token): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    private function token(User $user): string
    {
        $user->loadMissing('employee');

        return $user->createToken('Mobile operation test')->plainTextToken;
    }

    private function assigned(): array
    {
        $f = $this->responsibilityFoundation(5);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);

        return $f;
    }

    private function payload(array $f, int $quantity = 1, string $mode = 'reserve'): array
    {
        return [
            'mode' => $mode, 'warehouse_id' => $f['inventory']->warehouse_id, 'order_date' => now()->format('Y-m-d'),
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $f['product']->id, 'quantity' => $quantity, 'selling_price' => '200.00', 'discount_total' => '0', 'vat_rate' => '0']],
        ];
    }

    public function test_owner_and_assigned_employee_inventory_is_scoped_and_paginated(): void
    {
        $f = $this->assigned();
        $otherBrand = ProductBrand::factory()->create();
        $other = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 9]);
        $owner = $this->as($this->token($f['owner']))->getJson('/api/mobile/v1/workspace/inventory?per_page=1')->assertOk();
        $this->assertGreaterThan(1, $owner->json('last_page'));
        $staff = $this->as($this->token($f['employee']->user))->getJson('/api/mobile/v1/workspace/inventory')->assertOk();
        $this->assertContains($f['product']->id, collect($staff->json('data'))->pluck('product_id'));
        $this->assertNotContains($other->id, collect($staff->json('data'))->pluck('product_id'));
        $this->assertArrayNotHasKey('damaged_quantity', $staff->json('data.0'));
        $this->as($this->token($f['employee']->user))->getJson('/api/mobile/v1/workspace/products/'.$other->id)->assertNotFound();
    }

    public function test_dashboard_respects_owner_company_and_employee_scope(): void
    {
        $f = $this->assigned();
        $otherBrand = ProductBrand::factory()->create();
        $other = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 7]);
        $owner = $this->as($this->token($f['owner']))->getJson('/api/mobile/v1/dashboard')->assertOk();
        $staff = $this->as($this->token($f['employee']->user))->getJson('/api/mobile/v1/dashboard')->assertOk();
        $ownerCards = collect($owner->json('data.cards'))->keyBy('key');
        $staffCards = collect($staff->json('data.cards'))->keyBy('key');
        $this->assertGreaterThan($staffCards['products']['value'], $ownerCards['products']['value']);
        $this->assertSame('employee', $staff->json('data.inventory_intelligence.scope'));
    }

    public function test_order_reservation_is_atomic_and_overselling_is_rejected(): void
    {
        $f = $this->assigned();
        $staff = $f['employee']->user;
        $first = $this->as($this->token($staff))->postJson('/api/mobile/v1/workspace/orders', $this->payload($f, 3))->assertOk();
        $this->assertSame('reserved', $first->json('data.status'));
        $this->assertSame(3, $f['inventory']->refresh()->reserved_quantity);
        $this->as($this->token($staff))->postJson('/api/mobile/v1/workspace/orders', $this->payload($f, 3))->assertUnprocessable();
        $this->assertSame(3, $f['inventory']->refresh()->reserved_quantity);
    }

    public function test_draft_edit_reserve_and_cancel_use_inventory_workflow(): void
    {
        $f = $this->assigned();
        $staff = $f['employee']->user;
        $token = $this->token($staff);
        $draft = $this->as($token)->postJson('/api/mobile/v1/workspace/orders', $this->payload($f, 1, 'draft'))->assertOk();
        $id = $draft->json('data.id');
        $this->assertSame(0, $f['inventory']->refresh()->reserved_quantity);
        $this->as($token)->putJson('/api/mobile/v1/workspace/orders/'.$id, $this->payload($f, 2, 'draft'))->assertOk();
        $this->as($token)->postJson('/api/mobile/v1/workspace/orders/'.$id.'/reserve', ['idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame(2, $f['inventory']->refresh()->reserved_quantity);
        $this->as($token)->postJson('/api/mobile/v1/workspace/orders/'.$id.'/cancel', ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Customer cancelled'])->assertOk();
        $this->assertSame(0, $f['inventory']->refresh()->reserved_quantity);
    }

    public function test_unassigned_employee_cannot_create_or_read_unrelated_order(): void
    {
        $f = $this->assigned();
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);
        $token = $this->token($outsider);
        $this->as($token)->postJson('/api/mobile/v1/workspace/orders', $this->payload($f))->assertUnprocessable();
        $order = $this->as($this->token($f['owner']))->postJson('/api/mobile/v1/workspace/orders', $this->payload($f))->assertOk();
        $this->as($token)->getJson('/api/mobile/v1/workspace/orders/'.$order->json('data.id'))->assertForbidden();
    }

    public function test_product_mobile_edit_uses_full_erp_fields_without_changing_sku_or_stock(): void
    {
        $f = $this->assigned();
        $product = $f['product'];
        $sku = $product->sku;
        $available = $f['inventory']->available_quantity;
        $ownerToken = $this->token($f['owner']);
        $detail = $this->as($ownerToken)->getJson('/api/mobile/v1/workspace/products/'.$product->id)->assertOk();
        $fields = collect($detail->json('data.actions.0.fields'))->pluck('name');
        foreach (['name', 'brand_id', 'category_id', 'condition', 'model', 'processor', 'ram', 'storage',
            'screen_size', 'graphics', 'color', 'warranty', 'description', 'selling_price', 'cost_price'] as $field) {
            $this->assertContains($field, $fields);
        }
        $this->assertNotContains('sku', $fields);
        $this->assertNotContains('available_quantity', $fields);
        $data = ['name' => 'Updated Laptop', 'brand_id' => $product->brand_id, 'category_id' => $product->category_id,
            'condition' => 'used', 'model' => 'M-2026', 'processor' => 'Core i7', 'ram' => '16 GB',
            'storage' => '512 GB', 'screen_size' => '14 inch', 'graphics' => 'Integrated', 'color' => 'Black',
            'warranty' => 18, 'description' => 'Updated on mobile', 'selling_price' => '250.00',
            'cost_price' => '150.0000', 'sku' => 'MUST-NOT-CHANGE', 'available_quantity' => 999];
        $this->as($ownerToken)->postJson('/api/mobile/v1/workspace/products/'.$product->id.'/update', $data)->assertOk();
        $product->refresh();
        $this->assertSame('Updated Laptop', $product->name);
        $this->assertSame('used', $product->condition->value);
        $this->assertSame('Core i7', $product->processor);
        $this->assertSame($sku, $product->sku);
        $this->assertSame($available, $f['inventory']->refresh()->available_quantity);
        $this->as($this->token($f['employee']->user))->postJson('/api/mobile/v1/workspace/products/'.$product->id.'/update', $data)->assertForbidden();
    }

    public function test_restricted_order_and_product_prices_are_not_serialized(): void
    {
        $f = $this->assigned();
        $staff = $f['employee']->user;
        $order = $this->as($this->token($f['owner']))->postJson('/api/mobile/v1/workspace/orders', $this->payload($f))->assertOk();
        EmployeePermissionOverride::query()->create(['employee_id' => $f['employee']->id, 'permission_key' => OrderPermission::ViewSellingPrice->value, 'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $f['owner']->id]);
        EmployeePermissionOverride::query()->create(['employee_id' => $f['employee']->id, 'permission_key' => ProductPermission::ViewSellingPrice->value, 'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $f['owner']->id]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($f['employee']->id);
        $detail = $this->as($this->token($staff))->getJson('/api/mobile/v1/workspace/orders/'.$order->json('data.id'))->assertOk();
        $this->assertArrayNotHasKey('grand_total', $detail->json('data.fields'));
        $this->assertArrayNotHasKey('selling_price', $detail->json('data.items.0'));
        $product = $this->as($this->token($staff))->getJson('/api/mobile/v1/workspace/products/'.$f['product']->id)->assertOk();
        $this->assertArrayNotHasKey('selling_price', $product->json('data.fields'));
        $this->assertArrayNotHasKey('cost_price', $product->json('data.fields'));
    }

    public function test_task_actions_reject_unrelated_employee(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $assignee = $this->responsibilityUser(EmployeeRole::Staff);
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);
        $task = app(TaskService::class)->create(new CreateTaskData('Mobile task', null, TaskPriority::Normal, $assignee->employee->id, null, null, null, null, null, (string) Str::uuid()), $owner);
        $this->as($this->token($outsider))->getJson('/api/mobile/v1/workspace/tasks/'.$task->id)->assertForbidden();
        $this->as($this->token($outsider))->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/start')->assertForbidden();
        $this->as($this->token($assignee))->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/start')->assertOk();
        $this->as($this->token($assignee))->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/complete')->assertOk();
    }

    public function test_notification_read_is_owner_only(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $owner->notify(new MobileOperationalNotification('task.status_changed', ['title' => 'Private task', 'target_type' => 'task', 'target_id' => 99999]));
        $id = $owner->notifications()->firstOrFail()->id;
        $this->as($this->token($other))->postJson('/api/mobile/v1/workspace/notifications/'.$id.'/read')->assertNotFound();
        $this->as($this->token($owner))->postJson('/api/mobile/v1/workspace/notifications/'.$id.'/read')->assertOk();
        $this->assertNotNull($owner->notifications()->firstOrFail()->read_at);
    }

    public function test_inventory_notification_targets_follow_responsibility_scope(): void
    {
        $f = $this->assigned();
        $otherBrand = ProductBrand::factory()->create();
        $other = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        $unrelated = ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id]);
        $target = app(NotificationTarget::class);
        $own = ['target_type' => 'product_inventory', 'target_id' => $f['inventory']->id];
        $outside = ['target_type' => 'product_inventory', 'target_id' => $unrelated->id];

        $this->assertSame(['module' => 'inventory', 'id' => $f['inventory']->id], $target->resolve($f['employee']->user, $own));
        $this->assertNull($target->resolve($f['employee']->user, $outside));
        $this->assertSame(['module' => 'inventory', 'id' => $unrelated->id], $target->resolve($f['owner'], $outside));
    }

    public function test_push_token_registration_is_bound_to_user_and_session(): void
    {
        $one = $this->responsibilityUser(EmployeeRole::Owner);
        $two = $this->responsibilityUser(EmployeeRole::Staff);
        $device = (string) Str::uuid();
        $expo = 'ExponentPushToken[abc123]';
        $token = $this->token($one);
        $this->as($token)->postJson('/api/mobile/v1/devices', ['device_id' => $device, 'expo_token' => $expo, 'platform' => 'android'])->assertOk();
        $this->assertDatabaseCount('mobile_devices', 1);
        $this->as($this->token($two))->postJson('/api/mobile/v1/devices', ['device_id' => (string) Str::uuid(), 'expo_token' => $expo, 'platform' => 'android'])->assertStatus(409);
        $this->as($token)->postJson('/api/mobile/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('mobile_devices', 0);
    }

    public function test_chat_direct_messages_are_private_and_read_markers_are_monotonic(): void
    {
        $one = $this->responsibilityUser(EmployeeRole::Owner);
        $two = $this->responsibilityUser(EmployeeRole::Staff);
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);
        $first = $this->token($one);
        $second = $this->token($two);
        $third = $this->token($outsider);
        $c = $this->as($first)->postJson('/api/mobile/v1/chat', ['type' => 'direct', 'employee_id' => $two->employee->id])->assertOk()->json('data.id');
        $this->as($third)->getJson('/api/mobile/v1/chat/'.$c.'/messages')->assertForbidden();
        $this->as($third)->postJson('/api/mobile/v1/chat/'.$c.'/messages', ['body' => 'Intrusion'])->assertForbidden();
        $this->as($first)->postJson('/api/mobile/v1/chat/'.$c.'/messages', ['body' => 'First'])->assertCreated();
        $secondMessage = $this->as($first)->postJson('/api/mobile/v1/chat/'.$c.'/messages', ['body' => 'Second'])->assertCreated()->json('data.id');
        $history = $this->as($second)->getJson('/api/mobile/v1/chat/'.$c.'/messages')->assertOk();
        $this->assertCount(2, $history->json('data'));
        $this->as($second)->postJson('/api/mobile/v1/chat/'.$c.'/read', ['message_id' => $secondMessage])->assertOk();
        $this->as($second)->postJson('/api/mobile/v1/chat/'.$c.'/read', ['message_id' => $history->json('data.0.id')])->assertOk();
        $this->assertSame(0, $this->as($second)->getJson('/api/mobile/v1/chat')->json('unread_count'));
    }

    public function test_reopening_direct_chat_reactivates_previous_participant(): void
    {
        $one = $this->responsibilityUser(EmployeeRole::Owner);
        $two = $this->responsibilityUser(EmployeeRole::Staff);

        $first = $this->token($one);
        $second = $this->token($two);

        $conversationId = $this->as($first)
            ->postJson('/api/mobile/v1/chat', [
                'type' => 'direct',
                'employee_id' => $two->employee->id,
            ])
            ->assertOk()
            ->json('data.id');

        $conversation = \App\Models\Conversation::query()->findOrFail($conversationId);

        $conversation->participants()
            ->where('employee_id', $two->employee->id)
            ->update(['left_at' => now()]);

        $this->as($second)
            ->getJson('/api/mobile/v1/chat/'.$conversationId.'/messages')
            ->assertForbidden();

        $this->as($first)
            ->postJson('/api/mobile/v1/chat', [
                'type' => 'direct',
                'employee_id' => $two->employee->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversationId,
            'employee_id' => $two->employee->id,
            'left_at' => null,
        ]);

        $this->as($second)
            ->getJson('/api/mobile/v1/chat/'.$conversationId.'/messages')
            ->assertOk();
    }
    public function test_return_record_scope_and_receive_permission(): void
    {
        $f = $this->assigned();
        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        $other = Product::factory()->create();
        $order = Order::query()->create(['reference' => 'SO-MOBILE-OTHER', 'source' => 'manual', 'status' => 'fulfilled', 'warehouse_id' => $f['inventory']->warehouse_id, 'order_date' => now()->toDateString(), 'subtotal' => 0, 'discount_total' => 0, 'vat_total' => 0, 'grand_total' => 0, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $f['owner']->id]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $other->id, 'product_name' => $other->name, 'sku' => $other->sku, 'ordered_quantity' => 1, 'selling_price' => 0, 'discount_total' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'line_total' => 0]);
        $return = CustomerReturn::query()->create(['reference' => 'RET-MOBILE-OTHER', 'order_id' => $order->id, 'fulfillment_warehouse_id' => $f['inventory']->warehouse_id, 'receiving_warehouse_id' => $f['inventory']->warehouse_id, 'status' => 'draft', 'return_source' => 'manual', 'reported_at' => now(), 'created_by_user_id' => $f['owner']->id, 'idempotency_key' => (string) Str::uuid()]);
        $this->as($this->token($manager))->getJson('/api/mobile/v1/workspace/returns/'.$return->id)->assertForbidden();
        $this->as($this->token($manager))->postJson('/api/mobile/v1/workspace/returns/'.$return->id.'/receive', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->as($this->token($f['owner']))->getJson('/api/mobile/v1/workspace/returns/'.$return->id)->assertOk();
    }

    public function test_warranty_record_scope_and_status_permission(): void
    {
        $f = $this->assigned();
        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        $otherBrand = ProductBrand::factory()->create();
        $other = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        EmployeePermissionOverride::query()->create(['employee_id' => $manager->employee->id, 'permission_key' => WarrantyRepairPermission::View->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $f['owner']->id]);
        $case = WarrantyRepair::query()->create(['reference' => 'WAR-MOBILE-OTHER', 'product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'quantity' => 1, 'source' => 'order', 'issue_description' => 'Test issue', 'received_at' => now(), 'status' => 'received', 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $f['owner']->id]);
        $this->as($this->token($manager))->getJson('/api/mobile/v1/workspace/warranty/'.$case->id)->assertForbidden();
        $this->as($this->token($manager))->postJson('/api/mobile/v1/workspace/warranty/'.$case->id.'/under_inspection')->assertForbidden();
        $this->as($this->token($f['owner']))->getJson('/api/mobile/v1/workspace/warranty/'.$case->id)->assertOk();
        $this->as($this->token($f['owner']))->postJson('/api/mobile/v1/workspace/warranty/'.$case->id.'/under_inspection')->assertOk();
    }

    public function test_cross_user_order_and_return_idempotency_keys_do_not_expose_records(): void
    {
        $f = $this->assigned();
        $payload = $this->payload($f, 1, 'draft');
        $orderId = $this->as($this->token($f['owner']))->postJson('/api/mobile/v1/workspace/orders', $payload)->assertOk()->json('data.id');
        $this->as($this->token($f['employee']->user))->postJson('/api/mobile/v1/workspace/orders', $payload)->assertForbidden();
        $this->assertDatabaseCount('orders', 1);

        $key = (string) Str::uuid();
        CustomerReturn::query()->create(['reference' => 'RET-MOBILE-RETRY', 'order_id' => $orderId,
            'fulfillment_warehouse_id' => $f['inventory']->warehouse_id, 'receiving_warehouse_id' => $f['inventory']->warehouse_id,
            'status' => 'draft', 'return_source' => 'manual', 'reported_at' => now(),
            'created_by_user_id' => $f['owner']->id, 'idempotency_key' => $key]);
        $this->as($this->token($f['employee']->user))->postJson('/api/mobile/v1/workspace/returns', [
            'order_id' => $orderId, 'order_fulfillment_item_id' => 1, 'quantity' => 1, 'return_reason' => 'other',
            'idempotency_key' => $key,
        ])->assertForbidden();
        $this->assertDatabaseCount('customer_returns', 1);
    }

    public function test_order_and_return_actions_obey_explicit_permission_denials(): void
    {
        $f = $this->assigned();
        $staff = $f['employee']->user;
        $token = $this->token($staff);
        $ownerToken = $this->token($f['owner']);
        $draft = $this->as($ownerToken)->postJson('/api/mobile/v1/workspace/orders', $this->payload($f, 1, 'draft'))->assertOk()->json('data.id');

        foreach ([OrderPermission::Create, OrderPermission::UpdateDraft, OrderPermission::Reserve, OrderPermission::Cancel] as $permission) {
            EmployeePermissionOverride::query()->create(['employee_id' => $f['employee']->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $f['owner']->id]);
        }
        EmployeePermissionOverride::query()->create(['employee_id' => $f['employee']->id, 'permission_key' => CustomerReturnPermission::Create->value,
            'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $f['owner']->id]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($f['employee']->id);

        $this->as($token)->postJson('/api/mobile/v1/workspace/orders', $this->payload($f))->assertForbidden();
        $this->as($token)->putJson('/api/mobile/v1/workspace/orders/'.$draft, $this->payload($f, 1, 'draft'))->assertForbidden();
        $this->as($token)->postJson('/api/mobile/v1/workspace/orders/'.$draft.'/reserve', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->as($token)->postJson('/api/mobile/v1/workspace/orders/'.$draft.'/cancel', ['idempotency_key' => (string) Str::uuid(), 'reason' => 'No'])->assertForbidden();
        $this->as($token)->postJson('/api/mobile/v1/workspace/returns', ['order_id' => $draft])->assertForbidden();
    }

    public function test_return_and_warranty_routes_are_authenticated(): void
    {
        $this->getJson('/api/mobile/v1/workspace/returns')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/workspace/warranty')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/workspace/returns', ['order_id' => 1])->assertUnauthorized();
    }
}
