<?php

namespace Tests\Feature\AiAssistant;

use App\Actions\Orders\SaveAsShippedOrder;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\AiAssistantPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\StockRequestPurpose;
use App\Models\AiAssistantSetting;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AiAssistant\ErpReportToolRegistry;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\StockRequestService;
use App\Services\Returns\CustomerReturnUnifiedWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ErpReportToolRegistryTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'mock', 'app.timezone' => 'UTC', 'ai_assistant.business_timezone' => 'Asia/Dubai']);
        AiAssistantSetting::query()->create(['id' => 1, 'enabled' => true, 'provider' => 'mock', 'model' => 'gpt-6-luna']);
    }

    public function test_today_sales_tool_reports_period_and_distinguishes_order_rows_from_fulfilled_sales_by_platform(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $product = Product::factory()->create();
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5, 'average_cost' => '100.0000']);
        $amazon = MarketplacePlatform::factory()->create(['name' => 'Amazon', 'normalized_name' => 'amazon', 'code' => 'amazon']);
        $carrefour = MarketplacePlatform::factory()->create(['name' => 'Carrefour', 'normalized_name' => 'carrefour', 'code' => 'carrefour']);

        foreach ([$amazon, $carrefour] as $platform) {
            app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
                $warehouse->id, $platform->id, null, now('Asia/Dubai')->toDateString(), $owner->employee->id, null,
                [new OrderItemData($product->id, 1, '500.00')], (string) Str::uuid(),
            ), $owner);
        }
        DB::table('orders')->update(['order_date' => today('Asia/Dubai')->toDateString()]);

        $result = app(ErpReportToolRegistry::class)->call($owner, 'sales_orders_report', []);

        $this->assertSame(['requested' => 'today', 'from' => today('Asia/Dubai')->toDateString(), 'to' => today('Asia/Dubai')->toDateString(), 'timezone' => 'Asia/Dubai'], $result['period']);
        $this->assertSame('AED', $result['currency']);
        $this->assertSame('orders', $result['source']);
        $this->assertStringContainsString('not net sales', $result['metric_note']);
        $this->assertCount(2, $result['rows'], json_encode(['result' => $result, 'orders' => DB::table('orders')->get(['order_date', 'marketplace_platform_id', 'status'])->all()]));
        $this->assertSame(['Amazon', 'Carrefour'], collect($result['rows'])->pluck('platform')->sort()->values()->all(), json_encode($result['rows']));
        $this->assertSame('sales_summary', $result['fulfilled_sales_summary']['source']);
        $this->assertSame(['Amazon', 'Carrefour'], collect($result['fulfilled_sales_summary']['rows'])->pluck('platform')->sort()->values()->all());
    }

    public function test_natural_periods_use_server_generated_dubai_date_and_boundaries(): void
    {
        CarbonImmutable::setTestNow('2026-11-11 20:30:00 UTC');
        $user = $this->owner();
        $registry = app(ErpReportToolRegistry::class);
        $filters = new \ReflectionMethod($registry, 'filters');

        $today = $filters->invoke($registry, [], 'sales_orders_report');
        $this->assertSame('2026-11-12', $today['from']);
        $this->assertSame('2026-11-11 20:00:00', $today['from_utc']);
        $this->assertSame('2026-11-12 20:00:00', $today['until_utc']);

        $week = $filters->invoke($registry, ['period' => 'last_week'], 'sales_orders_report');
        $this->assertSame('2026-11-02', $week['from']);
        $this->assertSame('2026-11-08', $week['to']);

        $custom = $filters->invoke($registry, ['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-05'], 'sales_orders_report');
        $this->assertSame(['2026-11-01', '2026-11-05'], [$custom['from'], $custom['to']]);
        CarbonImmutable::setTestNow();
    }

    public function test_employee_allocations_tool_uses_existing_active_responsibility_assignments(): void
    {
        $foundation = $this->responsibilityFoundation(8);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($foundation), $foundation['owner']);

        $result = app(ErpReportToolRegistry::class)->call($foundation['owner'], 'employee_inventory_allocations', ['employee_id' => $foundation['employee']->id]);

        $this->assertSame('Current active allocations', $result['period']);
        $this->assertSame('AED', $result['currency']);
        $this->assertSame($foundation['employee']->name, $result['rows'][0]['employee']);
        $this->assertSame('HP', $result['rows'][0]['brand']);
    }

    public function test_stock_request_tool_returns_existing_status_and_reference(): void
    {
        $foundation = $this->responsibilityFoundation(5);
        app(InventoryAllocationService::class)->ensureShadowCoverage($foundation['inventory'], $foundation['owner']);
        $request = app(StockRequestService::class)->create(new CreateStockRequestData(
            StockRequestPurpose::PermanentTransfer, null, [new StockRequestItemData($foundation['inventory']->id, 1)],
            'Disposable AI reporting fixture', (string) Str::uuid(),
        ), $foundation['owner']);

        $result = app(ErpReportToolRegistry::class)->call($foundation['owner'], 'stock_requests_report', ['from' => today()->toDateString(), 'to' => today()->toDateString()]);

        $this->assertSame('stock_requests', $result['source']);
        $this->assertNotEmpty($result['rows'], json_encode($result));
        $this->assertArrayHasKey('reference', $result['rows'][0], json_encode($result['rows']));
        $this->assertSame($request->reference, $result['rows'][0]['reference'], json_encode($result['rows']));
        $this->assertSame($request->status->value, $result['rows'][0]['status']);
    }

    public function test_admin_ai_access_does_not_include_owner_only_inventory_cost_fields(): void
    {
        $foundation = $this->responsibilityFoundation(13);
        $admin = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($admin)->role(EmployeeRole::Admin)->create(['email' => $admin->email]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => AiAssistantPermission::Use->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $foundation['owner']->id,
            'reason' => 'AI reporting integration test',
        ]);

        $result = app(ErpReportToolRegistry::class)->call($admin->refresh(), 'product_inventory_report', ['product_id' => $foundation['product']->id]);

        $this->assertSame('current_inventory', $result['source']);
        $this->assertSame(13, $result['rows'][0]['available']);
        $this->assertArrayNotHasKey('average_cost', $result['rows'][0]);
        $this->assertArrayNotHasKey('cost_value', $result['rows'][0]);
    }

    public function test_customer_return_tool_reuses_existing_pending_qc_workflow_and_excludes_refund_amounts(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $product = Product::factory()->create();
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 3, 'average_cost' => '100.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, null, null, today()->toDateString(), $owner->employee->id, null,
            [new OrderItemData($product->id, 1, '500.00')], (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit([
            'order_id' => $order->id, 'receiving_warehouse_id' => $warehouse->id, 'physical_received' => true,
            'items' => [[
                'order_fulfillment_item_id' => $order->fulfillment->items->sole()->id,
                'quantity' => 1, 'return_reason' => 'other', 'qc_outcome' => 'pending',
            ]],
        ], $owner, false, (string) Str::uuid());

        $this->assertSame(CustomerReturnStatus::QcPending, $return->status);
        $result = app(ErpReportToolRegistry::class)->call($owner, 'customer_returns_report', [
            'from' => today()->toDateString(), 'to' => today()->toDateString(), 'status' => CustomerReturnStatus::QcPending->value,
        ]);

        $this->assertSame('returns', $result['source']);
        $this->assertSame($return->reference, $result['rows'][0]['return_reference']);
        $this->assertArrayNotHasKey('refund_amount', $result['rows'][0]);
        $this->assertArrayNotHasKey('refund_total', $result['rows'][0]);
    }

    private function owner(): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
