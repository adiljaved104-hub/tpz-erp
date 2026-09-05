<?php

namespace Tests\Feature\Orders;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Enums\WebSalesPermission;
use App\Filament\Pages\WebSalesDashboard;
use App\Filament\Resources\WebSalesOrders\Pages\CreateWebSalesOrder;
use App\Filament\Resources\WebSalesOrders\Pages\ListWebSalesOrders;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\WebSalesDashboardService;
use App\Services\Orders\WebSalesReadService;
use App\Services\Orders\WebSalesService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WebSalesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_channels_delivery_types_and_multiple_items_use_main_warehouse_and_reserve(): void
    {
        [$owner, $warehouse, $first, $firstInventory] = $this->foundation();
        $second = Product::factory()->create(['selling_price' => '400.00']);
        ProductInventory::factory()->create(['product_id' => $second->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 8, 'average_cost' => '100.0000']);

        foreach (WebSalesChannel::cases() as $channel) {
            $delivery = $channel === WebSalesChannel::WalkIn ? WebSalesDeliveryType::ShopPickup : WebSalesDeliveryType::Courier;
            $order = app(WebSalesService::class)->createConfirmed($this->data($first, $channel, $delivery, [
                new OrderItemData($first->id, 1, '1500.25'),
                new OrderItemData($second->id, 2, '400.00'),
            ]), $owner);

            $this->assertSame('MAIN', $order->warehouse->code);
            $this->assertSame(OrderStatus::Reserved, $order->status);
            $this->assertSame($channel, $order->web_sales_channel);
            $this->assertSame($delivery, $order->delivery_type);
            $this->assertSame(2, $order->items->count());
            $this->assertSame('2300.25', $order->grand_total);
        }

        $this->assertSame(4, Order::query()->webSales()->count());
        $this->assertSame(8, InventoryReservation::query()->count());
        $this->assertSame(20, $firstInventory->refresh()->available_quantity);
        $this->assertSame(4, $firstInventory->reserved_quantity);
    }

    public function test_insufficient_sellable_stock_is_rejected_without_partial_order(): void
    {
        [$owner, , $product, $inventory] = $this->foundation(2, 1);

        try {
            app(WebSalesService::class)->createConfirmed($this->data($product, quantity: 2), $owner);
            $this->fail('Insufficient stock should fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertSame(2, $inventory->refresh()->available_quantity);
            $this->assertSame(1, $inventory->reserved_quantity);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_shipping_and_delivery_are_idempotent_and_use_authoritative_cogs(): void
    {
        [$owner, , $product, $inventory] = $this->foundation(5, 0, '1217.1428');
        $service = app(WebSalesService::class);
        $order = $service->createConfirmed($this->data($product, quantity: 2, price: '1500.00'), $owner);
        $movementCount = StockMovement::query()->count();
        $key = (string) Str::uuid();

        $shipped = $service->ship($order, $key, $owner);
        $service->ship($shipped, $key, $owner);
        $afterShip = $inventory->refresh()->only(['available_quantity', 'reserved_quantity']);
        $service->deliver($shipped->refresh(), $owner);
        $service->deliver($shipped->refresh(), $owner);

        $this->assertSame(['available_quantity' => 3, 'reserved_quantity' => 0], $afterShip);
        $this->assertSame($afterShip, $inventory->refresh()->only(['available_quantity', 'reserved_quantity']));
        $this->assertNotNull($shipped->refresh()->delivered_at);
        $this->assertSame($movementCount + 1, StockMovement::query()->count());
        $this->assertSame('1217.1428', OrderFulfillmentItem::query()->sole()->inventory_unit_cost);
        $this->assertSame('2434.2856', OrderFulfillmentItem::query()->sole()->cogs_total);
        $this->assertSame('565.71', bcsub('3000.00', '2434.2856', 2));
    }

    public function test_cancellation_releases_reservation_without_changing_available(): void
    {
        [$owner, , $product, $inventory] = $this->foundation(6);
        $service = app(WebSalesService::class);
        $order = $service->createConfirmed($this->data($product, quantity: 2), $owner);

        $service->cancel($order, 'Customer cancelled', (string) Str::uuid(), $owner);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(6, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
    }

    public function test_walk_in_complete_sale_uses_direct_fulfilment_and_never_reserves(): void
    {
        [$owner, , $product, $inventory] = $this->foundation(4, 0, '100.0000');
        $data = $this->data($product, WebSalesChannel::WalkIn, WebSalesDeliveryType::ShopPickup, quantity: 1);

        $order = app(WebSalesService::class)->completeSale($data, $owner);
        $retry = app(WebSalesService::class)->completeSale($data, $owner);

        $this->assertSame($order->id, $retry->id);
        $this->assertSame(OrderStatus::Fulfilled, $order->status);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(3, $inventory->refresh()->available_quantity);
    }

    public function test_staff_scope_and_financial_projection_are_enforced_in_sql(): void
    {
        [$owner, , $product] = $this->foundation();
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $this->assignProduct($staff, $product, $owner);
        $this->assignProduct($other, $product, $owner);
        app(WebSalesService::class)->createConfirmed($this->data($product), $staff);
        app(WebSalesService::class)->createConfirmed($this->data($product), $other);

        $query = app(WebSalesReadService::class)->orders($staff);
        $this->assertSame(1, $query->count());
        $sql = strtolower($query->toSql());
        $this->assertStringNotContainsString('cogs_total', $sql);
        $this->assertStringNotContainsString('gross_profit', $sql);
        $this->assertSame($staff->id, $query->sole()->created_by_user_id);
    }

    public function test_manager_team_scope_and_dashboard_completed_metrics_are_correct(): void
    {
        [$owner, , $product] = $this->foundation(10, 0, '100.0000');
        $team = Team::query()->create(['name' => 'Web Team', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager, $team->id);
        $staff = $this->user(EmployeeRole::Staff, $team->id);
        $outsider = $this->user(EmployeeRole::Staff);
        foreach ([$manager, $staff, $outsider] as $user) {
            $this->assignProduct($user, $product, $owner);
        }
        $staffOrder = app(WebSalesService::class)->createConfirmed($this->data($product, price: '300.00'), $staff);
        app(WebSalesService::class)->ship($staffOrder, (string) Str::uuid(), $owner);
        app(WebSalesService::class)->createConfirmed($this->data($product), $outsider);

        $this->assertSame(1, app(WebSalesReadService::class)->orders($manager)->count());
        [$from, $to] = app(WebSalesDashboardService::class)->range('today');
        $metrics = app(WebSalesDashboardService::class)->metrics($owner, $from, $to);
        $this->assertSame(2, $metrics['summary']['total_orders']);
        $this->assertSame(1, $metrics['summary']['units_sold']);
        $this->assertSame(0, bccomp('300.00', (string) $metrics['summary']['revenue'], 2));
        $this->assertSame(0, bccomp('100.00', (string) $metrics['summary']['cogs'], 2));
        $this->assertSame('200.00', $metrics['summary']['gross_profit']);
    }

    public function test_admin_scope_direct_record_authorization_and_employee_overrides_are_enforced(): void
    {
        [$owner, , $product] = $this->foundation();
        $first = $this->user(EmployeeRole::Staff);
        $second = $this->user(EmployeeRole::Staff);
        foreach ([$first, $second] as $user) {
            $this->assignProduct($user, $product, $owner);
        }
        $firstOrder = app(WebSalesService::class)->createConfirmed($this->data($product), $first);
        $secondOrder = app(WebSalesService::class)->createConfirmed($this->data($product), $second);

        $admin = $this->user(EmployeeRole::Admin);
        $adminQuery = app(WebSalesReadService::class)->orders($admin);
        $this->assertSame(2, $adminQuery->count());
        $this->assertStringNotContainsString('cogs_total', strtolower($adminQuery->toSql()));
        $this->assertStringNotContainsString('gross_profit', strtolower($adminQuery->toSql()));

        $this->actingAs($first);
        $this->assertTrue(WebSalesOrderResource::canView($firstOrder));
        $this->assertFalse(WebSalesOrderResource::canView($secondOrder));

        $denied = $this->user(EmployeeRole::Staff);
        $this->assignProduct($denied, $product, $owner);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $denied->employee->id,
            'permission_key' => WebSalesPermission::Create->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Web Sales regression',
        ]);
        $this->expectException(AuthorizationException::class);
        app(WebSalesService::class)->createConfirmed($this->data($product), $denied);
    }

    public function test_filament_pages_are_simple_and_server_side_authorized(): void
    {
        [$owner, , $product] = $this->foundation();
        $this->actingAs($owner);
        Livewire::test(ListWebSalesOrders::class)->assertOk();
        Livewire::test(WebSalesDashboard::class)->assertOk();
        Livewire::test(CreateWebSalesOrder::class)
            ->assertSee('Customer Name')->assertSee('WhatsApp / Phone')->assertSee('Save & Confirm')
            ->assertDontSee('Warehouse Select')->assertDontSee('Handled By')->assertDontSee('Cost Price')
            ->fillForm([
                'customer_name' => 'Web Customer', 'customer_phone' => '+971 50 111 2233',
                'web_sales_channel' => 'whatsapp', 'delivery_type' => 'courier', 'courier_name' => 'Aramex',
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'selling_price' => '1500.00']],
            ])->call('create')->assertHasNoFormErrors();

        $inactive = $this->user(EmployeeRole::Staff);
        $inactive->employee->update(['status' => false]);
        $this->actingAs($inactive->refresh());
        Livewire::test(ListWebSalesOrders::class)->assertForbidden();
    }

    public function test_web_sales_dashboard_renders_responsive_cards_tables_and_reactive_periods(): void
    {
        [$owner] = $this->foundation();
        $this->actingAs($owner);

        $dashboard = Livewire::test(WebSalesDashboard::class)
            ->assertOk()
            ->assertSeeHtml('class="web-sales-kpi-grid"')
            ->assertSeeHtml('data-web-sales-metric="total_orders"')
            ->assertSeeHtml('data-web-sales-metric="revenue"')
            ->assertSeeHtml('data-web-sales-metric="cogs"')
            ->assertSeeHtml('data-web-sales-metric="gross_profit"')
            ->assertSeeHtml('data-web-sales-metric="operating_expenses"')
            ->assertSeeHtml('data-web-sales-metric="net_profit"')
            ->assertSeeHtml('class="web-sales-status-grid"')
            ->assertSee('Employee Performance')
            ->assertSee('Sales by Channel')
            ->assertSee('Top Products');

        foreach (['yesterday', 'week', 'month'] as $period) {
            $dashboard->call('setPeriod', $period)
                ->assertSet('period', $period)
                ->assertHasNoErrors();
        }

        $dashboard->call('setPeriod', 'custom')
            ->assertSet('period', 'custom')
            ->assertSeeHtml('data-web-sales-custom-range')
            ->set('from', '2026-08-01')
            ->set('to', '2026-08-26')
            ->call('applyRange')
            ->assertSet('period', 'custom')
            ->assertHasNoErrors()
            ->assertSee('01 Aug 2026 – 26 Aug 2026');
    }

    public function test_staff_web_sales_dashboard_adapts_without_financial_cards_or_columns(): void
    {
        [$owner, , $product] = $this->foundation();
        $staff = $this->user(EmployeeRole::Staff);
        $this->assignProduct($staff, $product, $owner);
        $this->actingAs($staff);

        Livewire::test(WebSalesDashboard::class)
            ->assertOk()
            ->assertSeeHtml('data-web-sales-metric="total_orders"')
            ->assertSeeHtml('data-web-sales-metric="units_sold"')
            ->assertSeeHtml('data-web-sales-metric="revenue"')
            ->assertDontSeeHtml('data-web-sales-metric="cogs"')
            ->assertDontSeeHtml('data-web-sales-metric="gross_profit"')
            ->assertDontSeeHtml('data-web-sales-metric="operating_expenses"')
            ->assertDontSeeHtml('data-web-sales-metric="net_profit"')
            ->assertDontSee('Gross Profit')
            ->assertDontSee('COGS')
            ->assertDontSee('Net Profit');
    }

    /** @return array{User, Warehouse, Product, ProductInventory} */
    private function foundation(int $available = 20, int $reserved = 0, string $averageCost = '500.0000'): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create(['selling_price' => '1500.00']);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => $available, 'reserved_quantity' => $reserved, 'average_cost' => $averageCost,
        ]);

        return [$owner, $warehouse, $product, $inventory];
    }

    /** @param array<int, OrderItemData>|null $items */
    private function data(Product $product, WebSalesChannel $channel = WebSalesChannel::WhatsApp, WebSalesDeliveryType $delivery = WebSalesDeliveryType::Courier, ?array $items = null, int $quantity = 1, string $price = '1500.00'): WebSalesOrderData
    {
        return new WebSalesOrderData(
            'Test Customer', '+971 50 123 4567', $channel, $delivery,
            $delivery === WebSalesDeliveryType::Courier ? 'Aramex' : null,
            $delivery === WebSalesDeliveryType::Courier ? 'AWB-100' : null,
            $items ?? [new OrderItemData($product->id, $quantity, $price)], (string) Str::uuid(),
        );
    }

    private function user(EmployeeRole $role, ?int $teamId = null): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $teamId]);

        return $user->refresh();
    }

    private function assignProduct(User $user, Product $product, User $owner): void
    {
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $user->employee->id, 'team_id_at_assignment' => $user->employee->team_id,
            'team_name_at_assignment' => $user->employee->team?->name, 'assigned_by_user_id' => $owner->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
    }
}
