<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryPermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\WebSalesOrders\Pages\CreateWebSalesOrder;
use App\Models\InventoryAllocationSetting;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Orders\WebSalesService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class OrderStockSourceAccessTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_staff_and_manager_manual_sources_are_hidden_unless_explicitly_allowed(): void
    {
        $foundation = $this->foundation();
        foreach ([EmployeeRole::Staff, EmployeeRole::Manager] as $role) {
            $actor = $this->responsibilityUser($role);
            foreach ([CreateOrder::class, CreateWebSalesOrder::class] as $page) {
                Livewire::actingAs($actor)->test($page)
                    ->assertOk()->assertDontSee('Stock Source / Consume From')
                    ->assertDontSee('Split Across Another Source')->assertDontSee('Allocation Holder');
            }
            app(EmployeePermissionOverrideService::class)->change($actor->employee,
                InventoryPermission::ConsumeFromAllAllocations->value, EmployeePermissionEffect::Allow, null, $foundation['owner']);
            foreach ([CreateOrder::class, CreateWebSalesOrder::class] as $page) {
                Livewire::actingAs($actor->fresh())->test($page)
                    ->assertOk()->assertSee('Stock Source / Consume From')->assertSee('Split Across Another Source');
            }
        }
    }

    public function test_owner_and_admin_keep_manual_source_ui(): void
    {
        foreach ([EmployeeRole::Owner, EmployeeRole::Admin] as $role) {
            $actor = $this->responsibilityUser($role);
            foreach ([CreateOrder::class, CreateWebSalesOrder::class] as $page) {
                Livewire::actingAs($actor)->test($page)->assertOk()->assertSee('Stock Source / Consume From');
            }
        }
    }

    public function test_hidden_stale_source_fields_are_not_dehydrated_and_web_sale_uses_own_stock(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $service = app(InventoryAllocationService::class);
        $own = $service->employeeAccount($actor->employee->id);
        $foreign = $service->employeeAccount($foundation['owner']->employee->id);
        $service->reconcile($foundation['inventory'], $own, 2, $foundation['owner'], 'Own stock');
        $service->reconcile($foundation['inventory'], $foreign, 2, $foundation['owner'], 'Other stock');
        foreach ([CreateOrder::class, CreateWebSalesOrder::class] as $page) {
            $component = Livewire::actingAs($actor)->test($page)->fillForm([
                'warehouse_id' => $foundation['inventory']->warehouse_id,
                'handled_by_employee_id' => $actor->employee->id,
                'order_date' => now()->toDateString(),
                'customer_name' => 'Test Customer', 'customer_phone' => '+971501234567',
                'web_sales_channel' => 'whatsapp', 'delivery_type' => 'shop_pickup',
                'items' => [['product_id' => $foundation['product']->id, 'quantity' => 1,
                    'selling_price' => '100.00', 'allocation_sources' => [['account_id' => $foreign->id, 'quantity' => 1]]]],
            ]);
            $state = $component->instance()->form->getState();
            $this->assertArrayNotHasKey('allocation_sources', array_values($state['items'])[0]);
            if ($page === CreateWebSalesOrder::class) {
                $component->call('create')->assertHasNoFormErrors();
            }
        }
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, $own->balances()->where('product_inventory_id', $foundation['inventory']->id)->value('reserved_quantity'));
        $this->assertSame(0, $foreign->balances()->where('product_inventory_id', $foundation['inventory']->id)->value('reserved_quantity'));
    }

    public function test_automatic_sources_use_own_then_team_without_foreign_fallback_and_retry_is_idempotent(): void
    {
        $foundation = $this->foundation();
        $team = Team::query()->create(['name' => 'Sales Team', 'status' => true]);
        $foundation['employee']->update(['team_id' => $team->id]);
        $actor = $foundation['employee']->user->fresh();
        $service = app(InventoryAllocationService::class);
        $own = $service->employeeAccount($actor->employee->id);
        $teamAccount = $service->teamAccount($team->id);
        $foreign = $service->employeeAccount($foundation['owner']->employee->id);
        foreach ([[$own, 1], [$teamAccount, 1], [$foreign, 4]] as [$account, $quantity]) {
            $service->reconcile($foundation['inventory'], $account, $quantity, $foundation['owner'], 'Explicit ownership');
        }
        InventoryAllocationSetting::query()->whereKey(1)->update(['enforcement_mode' => InventoryAllocationMode::Strict->value]);
        try {
            app(SaveAndReserveOrder::class)->handle($this->orderData($foundation, $actor, 3), $actor);
            $this->fail('Insufficient own/team stock must not consume another employee or System.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Stock Request', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 0);
        $this->assertSame(0, $foundation['inventory']->fresh()->reserved_quantity);
        $data = $this->orderData($foundation, $actor, 2);
        $order = app(SaveAndReserveOrder::class)->handle($data, $actor);
        $this->assertSame($order->id, app(SaveAndReserveOrder::class)->handle($data, $actor)->id);
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 2);
        foreach ([$own, $teamAccount] as $account) {
            $this->assertSame(1, $account->balances()->where('product_inventory_id', $foundation['inventory']->id)->value('reserved_quantity'));
        }
        $this->assertSame(0, $foreign->balances()->where('product_inventory_id', $foundation['inventory']->id)->value('reserved_quantity'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_shadow_automatic_system_fallback_is_preserved_and_audited(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $service = app(InventoryAllocationService::class);
        $own = $service->employeeAccount($actor->employee->id);
        $service->reconcile($foundation['inventory'], $own, 1, $foundation['owner'], 'Own allocation');
        app(SaveAndReserveOrder::class)->handle($this->orderData($foundation, $actor, 2), $actor);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'legacy_system_reservation',
            'quantity' => 1, 'from_account_id' => $service->systemAccount()->id]);
        $this->assertSame(1, $own->balances()->where('product_inventory_id', $foundation['inventory']->id)->value('reserved_quantity'));
    }

    public function test_manual_service_payload_is_rejected_for_both_own_and_foreign_sources_in_orders_and_web_sales(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $service = app(InventoryAllocationService::class);
        $own = $service->employeeAccount($actor->employee->id);
        $foreign = $service->employeeAccount($foundation['owner']->employee->id);
        foreach ([$own, $foreign] as $account) {
            $service->reconcile($foundation['inventory'], $account, 2, $foundation['owner'], 'Explicit ownership');
            foreach (['order', 'web_sale'] as $type) {
                try {
                    if ($type === 'order') {
                        app(SaveAndReserveOrder::class)->handle($this->orderData($foundation, $actor, 1, [$account->id => 1]), $actor);
                    } else {
                        app(WebSalesService::class)->createConfirmed($this->webData($foundation, [$account->id => 1]), $actor);
                    }
                    $this->fail('Manual source payload requires explicit permission.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('items.0.allocation_sources', $exception->errors());
                    $this->assertStringContainsString('not authorized', $exception->getMessage());
                }
            }
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 0);
        $this->assertSame(0, $foundation['inventory']->fresh()->reserved_quantity);
    }

    public function test_explicit_permission_allows_foreign_split_sources_in_orders_and_web_sales(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        app(EmployeePermissionOverrideService::class)->change($actor->employee,
            InventoryPermission::ConsumeFromAllAllocations->value, EmployeePermissionEffect::Allow, null, $foundation['owner']);
        $actor = $actor->fresh();
        $service = app(InventoryAllocationService::class);
        $first = $service->employeeAccount($foundation['owner']->employee->id);
        $second = $service->teamAccount(Team::query()->create(['name' => 'Other Team', 'status' => true])->id);
        foreach ([$first, $second] as $account) {
            $service->reconcile($foundation['inventory'], $account, 2, $foundation['owner'], 'Explicit ownership');
        }
        $sources = [$first->id => 1, $second->id => 1];
        app(SaveAndReserveOrder::class)->handle($this->orderData($foundation, $actor, 2, $sources), $actor);
        app(WebSalesService::class)->createConfirmed($this->webData($foundation, $sources, 2), $actor);
        foreach ([$first, $second] as $account) {
            $this->assertSame(2, $account->balances()->where('product_inventory_id', $foundation['inventory']->id)->value('reserved_quantity'));
        }
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 4);
    }

    private function foundation(): array
    {
        $foundation = $this->responsibilityFoundation(8);
        $foundation['inventory']->update(['warehouse_id' => Warehouse::query()->where('code', 'MAIN')->sole()->id]);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);
        app(InventoryAllocationService::class)->ensureShadowCoverage($foundation['inventory'], $foundation['owner']);

        return $foundation;
    }

    private function orderData(array $foundation, User $actor, int $quantity, ?array $sources = null): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData($foundation['inventory']->warehouse_id, null, null, now()->toDateString(),
            $actor->employee->id, null, [new OrderItemData($foundation['product']->id, $quantity, '100.00', allocationSources: $sources)], (string) Str::uuid());
    }

    private function webData(array $foundation, array $sources, int $quantity = 1): WebSalesOrderData
    {
        return new WebSalesOrderData('Test Customer', '+971501234567', WebSalesChannel::WhatsApp, WebSalesDeliveryType::ShopPickup,
            null, null, [new OrderItemData($foundation['product']->id, $quantity, '100.00', allocationSources: $sources)], (string) Str::uuid());
    }
}
