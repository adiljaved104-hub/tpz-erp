<?php

namespace Tests\Feature\Mobile;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\ChatPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\ProductPermission;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarningCategory;
use App\Services\Mobile\StockStatus;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MobileStagingPolishTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation { responsibilityUser as foundationResponsibilityUser; }

    protected function responsibilityUser(EmployeeRole $role, ?Team $team = null): User
    {
        $user = $this->foundationResponsibilityUser($role, $team);
        $email = 'mobile-polish-'.$user->id.'@techpointzone.com';
        $user->forceFill(['email' => $email])->save();
        $user->employee->forceFill(['email' => $email])->save();

        return $user->refresh();
    }

    private function token(User $user): string
    {
        return $user->createToken('Mobile polish test')->plainTextToken;
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($this->token($user));
    }

    public function test_workspace_capabilities_and_dashboard_targets_are_permission_aware(): void
    {
        $f = $this->responsibilityFoundation(5);
        $other = Product::factory()->create();
        ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 9]);
        $owner = $this->as($f['owner'])->getJson('/api/mobile/v1/workspace/modules')->assertOk();
        $ownerKeys = collect($owner->json('data'))->pluck('key');
        $this->assertContains('sales', $ownerKeys);
        $this->assertContains('purchases', $ownerKeys);
        $this->assertContains('hr', $ownerKeys);
        $cards = $this->as($f['owner'])->getJson('/api/mobile/v1/dashboard?period=today')->assertOk()->json('data.cards');
        $this->assertNotEmpty($cards);
        foreach ($cards as $card) {
            $this->assertArrayHasKey('target', $card);
            $this->assertContains($card['target']['module'], $ownerKeys);
        }
        $orders = collect($cards)->firstWhere('key', 'orders');
        $this->assertSame(['module' => 'sales', 'filter' => ['period' => 'today']], $orders['target']);
        $staffKeys = collect($this->as($f['employee']->user)->getJson('/api/mobile/v1/workspace/modules')->assertOk()->json('data'))->pluck('key');
        $this->assertNotContains('purchases', $staffKeys);
        $this->assertContains('hr', $staffKeys);
        $this->as($f['employee']->user)->getJson('/api/mobile/v1/workspace/purchases')->assertForbidden();
        EmployeePermissionOverride::query()->create([
            'employee_id' => $f['employee']->id, 'permission_key' => ChatPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $f['owner']->id,
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($f['employee']->id);
        $withoutChat = collect($this->as($f['employee']->user)->getJson('/api/mobile/v1/workspace/modules')->assertOk()->json('data'))->pluck('key');
        $this->assertNotContains('chat', $withoutChat);
        $this->as($f['employee']->user)->getJson('/api/mobile/v1/chat')->assertForbidden();
    }

    public function test_mobile_purchase_uses_erp_actions_and_hides_cost_from_unauthorized_users(): void
    {
        $f = $this->responsibilityFoundation(5);
        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        $staff = $f['employee']->user;
        $base = '/api/mobile/v1/workspace/purchases';
        $payload = ['warehouse_id' => $f['inventory']->warehouse_id, 'purchase_date' => now()->toDateString(),
            'items' => [['product_id' => $f['product']->id, 'ordered_quantity' => 2, 'unit_cost' => '120.00', 'vat_rate' => '0']]];
        $this->as($staff)->postJson($base, $payload)->assertForbidden();
        $created = $this->as($f['owner'])->postJson($base, $payload)->assertOk();
        $id = $created->json('data.id');
        $this->assertSame('draft', $created->json('data.status'));
        $this->assertSame(5, $f['inventory']->refresh()->available_quantity);
        $this->assertArrayHasKey('unit_cost', $created->json('data.items.0'));
        $managerView = $this->as($manager)->getJson($base.'/'.$id)->assertOk();
        $this->assertArrayNotHasKey('grand_total', $managerView->json('data.fields'));
        $this->assertArrayNotHasKey('unit_cost', $managerView->json('data.items.0'));
        $this->as($manager)->putJson($base.'/'.$id, $payload)->assertForbidden();
        $this->as($f['owner'])->postJson($base.'/'.$id.'/approve', ['reason' => 'Stock order'])->assertOk();
        $receipt = $this->as($f['owner'])->postJson($base.'/'.$id.'/receive', [
            'received_at' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'items' => [['purchase_item_id' => Purchase::query()->findOrFail($id)->items()->firstOrFail()->id,
                'accepted_quantity' => 2, 'damaged_quantity' => 0, 'rejected_quantity' => 0]],
        ])->assertOk();
        $this->assertSame('fully_received', $receipt->json('data.status'));
        $this->assertSame(7, $f['inventory']->refresh()->available_quantity);
        $this->assertCount(1, $receipt->json('data.receipts'));
    }

    public function test_mobile_hr_directory_notices_and_warnings_remain_private(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);
        $base = '/api/mobile/v1/workspace/hr';
        $ownerEmployees = $this->as($owner)->getJson($base.'/employees')->assertOk()->json('data');
        $this->assertCount(3, $ownerEmployees);
        $own = $this->as($staff)->getJson($base.'/employees')->assertOk()->json('data');
        $this->assertCount(1, $own);
        $this->assertSame($staff->employee->id, $own[0]['id']);
        $this->assertArrayNotHasKey('email', $own[0]);
        $this->as($staff)->getJson($base.'/employees/'.$outsider->employee->id)->assertNotFound();

        $notice = $this->as($owner)->postJson($base.'/notices', [
            'audience_type' => 'selected', 'employee_ids' => [$staff->employee->id],
            'title' => 'Private notice', 'content' => 'For this employee', 'priority' => 'normal',
            'published_at' => now()->toDateTimeString(), 'acknowledgment_required' => true,
        ])->assertOk();
        $noticeId = $notice->json('data.id');
        $this->as($staff)->getJson($base.'/notices/'.$noticeId)->assertOk();
        $this->as($outsider)->getJson($base.'/notices/'.$noticeId)->assertNotFound();
        $this->as($staff)->postJson($base.'/notices', [])->assertForbidden();

        $category = WarningCategory::query()->create(['name' => 'Conduct', 'status' => true, 'created_by_user_id' => $owner->id]);
        $warning = $this->as($owner)->postJson($base.'/warnings', [
            'employee_id' => $staff->employee->id, 'warning_level' => 'verbal',
            'warning_category_id' => $category->id, 'title' => 'Private warning',
            'description' => 'Follow policy', 'issued_date' => now()->toDateString(), 'acknowledgment_required' => true,
        ])->assertOk();
        $warningId = $warning->json('data.id');
        $this->as($staff)->getJson($base.'/warnings/'.$warningId)->assertOk();
        $this->as($outsider)->getJson($base.'/warnings/'.$warningId)->assertNotFound();
        $this->as($staff)->postJson($base.'/warnings', [])->assertForbidden();
        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id, 'permission_key' => HrPermission::WarningIssue->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $picker = $this->as($manager)->getJson($base.'/options')->assertOk()->json('data.employees');
        $this->assertSame([$manager->employee->id], collect($picker)->pluck('id')->all());
    }

    public function test_direct_return_search_is_scoped_and_creation_uses_existing_service(): void
    {
        $f = $this->responsibilityFoundation(5);
        app(ResponsibilityAssignmentService::class)
            ->create($this->assignmentData($f), $f['owner']);
        $base = '/api/mobile/v1/workspace';
        $payload = ['mode' => 'reserve', 'warehouse_id' => $f['inventory']->warehouse_id,
            'order_date' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'external_order_number' => 'MOBILE-RETURN-001',
            'items' => [['product_id' => $f['product']->id, 'quantity' => 1, 'selling_price' => '200',
                'discount_total' => '0', 'vat_rate' => '0']]];
        $id = $this->as($f['owner'])->postJson($base.'/orders', $payload)->assertOk()->json('data.id');
        $this->as($f['owner'])->postJson($base.'/orders/'.$id.'/fulfill', ['idempotency_key' => (string) Str::uuid()])->assertOk();
        $staff = $f['employee']->user;
        foreach ([CustomerReturnPermission::View, CustomerReturnPermission::Create] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $f['employee']->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $f['owner']->id,
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($f['employee']->id);
        $eligible = $this->as($staff)->getJson($base.'/returns/eligible-orders?q=MOBILE-RETURN')->assertOk()->json('data');
        $this->assertContains($id, collect($eligible)->pluck('id'));
        $options = $this->as($staff)->getJson($base.'/returns/options?order_id='.$id)->assertOk();
        $fields = collect($options->json('data.fields'))->keyBy('name');
        $fulfillmentItem = $fields['order_fulfillment_item_id']['options'][0]['value'];
        $key = (string) Str::uuid();
        $return = $this->as($staff)->postJson($base.'/returns', [
            'order_id' => $id, 'order_fulfillment_item_id' => $fulfillmentItem,
            'receiving_warehouse_id' => $f['inventory']->warehouse_id,
            'quantity' => 1, 'return_reason' => 'other', 'idempotency_key' => $key,
        ])->assertOk();
        $this->assertSame('draft', $return->json('data.status'));
        $this->as($staff)->postJson($base.'/returns', [
            'order_id' => $id, 'order_fulfillment_item_id' => $fulfillmentItem,
            'receiving_warehouse_id' => $f['inventory']->warehouse_id,
            'quantity' => 1, 'return_reason' => 'other', 'idempotency_key' => $key,
        ])->assertOk();
        $this->assertDatabaseCount('customer_returns', 1);
        $afterReturn = $this->as($staff)->getJson($base.'/returns/eligible-orders')->assertOk()->json('data');
        $this->assertNotContains($id, collect($afterReturn)->pluck('id'));

        $outsider = $this->responsibilityUser(EmployeeRole::Manager);
        $outside = $this->as($outsider)->getJson($base.'/returns/eligible-orders')->assertOk()->json('data');
        $this->assertNotContains($id, collect($outside)->pluck('id'));
        $this->as($outsider)->getJson($base.'/returns/options?order_id='.$id)->assertForbidden();
        $this->as($outsider)->postJson($base.'/returns', [
            'order_id' => $id, 'order_fulfillment_item_id' => $fulfillmentItem,
            'quantity' => 1, 'return_reason' => 'other', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    public function test_price_restricted_product_editor_never_receives_or_changes_price_fields(): void
    {
        $f = $this->responsibilityFoundation(5);
        app(ResponsibilityAssignmentService::class)
            ->create($this->assignmentData($f), $f['owner']);
        $staff = $f['employee']->user;
        foreach ([ProductPermission::Update] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $f['employee']->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $f['owner']->id,
            ]);
        }
        foreach ([ProductPermission::ViewSellingPrice, ProductPermission::ViewCostPrice,
            ProductPermission::EditSellingPrice, ProductPermission::EditCostPrice] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $f['employee']->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $f['owner']->id,
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($f['employee']->id);
        $url = '/api/mobile/v1/workspace/products/'.$f['product']->id;
        $detail = $this->as($staff)->getJson($url)->assertOk()->json('data');
        $this->assertArrayNotHasKey('selling_price', $detail['fields']);
        $this->assertArrayNotHasKey('cost_price', $detail['fields']);
        $editFields = collect($detail['actions'])->firstWhere('key', 'update')['fields'];
        $this->assertNotContains('selling_price', collect($editFields)->pluck('name'));
        $this->assertNotContains('cost_price', collect($editFields)->pluck('name'));
        $this->as($staff)->postJson($url.'/update', [
            'name' => $f['product']->name, 'brand_id' => $f['product']->brand_id,
            'category_id' => $f['product']->category_id, 'condition' => $f['product']->condition->value,
            'warranty' => $f['product']->warranty, 'selling_price' => '9999.00',
        ])->assertForbidden();
    }

    public function test_low_stock_destination_matches_dashboard_sku_aggregation_across_warehouses(): void
    {
        $threshold = app(StockStatus::class)->low();
        $f = $this->responsibilityFoundation($threshold);
        ProductInventory::factory()->create([
            'product_id' => $f['product']->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'available_quantity' => 1,
        ]);
        $lowProduct = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $lowProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => $threshold,
        ]);
        $outProduct = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $outProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 0,
        ]);

        $cards = collect($this->as($f['owner'])->getJson('/api/mobile/v1/dashboard')->assertOk()->json('data.cards'));
        $lowRows = $this->as($f['owner'])->getJson('/api/mobile/v1/workspace/inventory?status=low_stock')->assertOk()->json('data');
        $outRows = $this->as($f['owner'])->getJson('/api/mobile/v1/workspace/inventory?status=out_of_stock')->assertOk()->json('data');

        $this->assertSame(1, $cards->firstWhere('key', 'low_stock')['value']);
        $this->assertSame([$lowProduct->id], collect($lowRows)->pluck('product_id')->unique()->values()->all());
        $this->assertSame(1, $cards->firstWhere('key', 'out_of_stock')['value']);
        $this->assertSame([$outProduct->id], collect($outRows)->pluck('product_id')->unique()->values()->all());
    }
}
