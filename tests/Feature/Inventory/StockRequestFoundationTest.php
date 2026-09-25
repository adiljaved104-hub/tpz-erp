<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\EmployeeRole;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\StockRequestPurpose;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Models\Employee;
use App\Models\InventoryAllocationBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\StockRequest;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\StockRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockRequestFoundationTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_multi_product_permanent_transfer_request_snapshots_sources_without_mutating_stock(): void
    {
        $f = $this->responsibilityFoundation(10);
        $second = ProductInventory::factory()->create([
            'product_id' => Product::factory(['brand_id' => $f['brand']->id, 'brand' => $f['brand']->name]),
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 8,
        ]);
        $sourceEmployee = Employee::factory()->create(['employee_id' => 'TPZ-0200', 'name' => 'Source Employee', 'status' => true]);
        $team = Team::query()->create(['name' => 'E-Commerce Team', 'status' => true]);
        $allocations = app(InventoryAllocationService::class);
        foreach ([$f['inventory'], $second] as $inventory) {
            $allocations->ensureShadowCoverage($inventory, $f['owner']);
        }
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($sourceEmployee->id), 3, $f['owner'], 'Employee source');
        $allocations->reconcile($f['inventory'], $allocations->teamAccount($team->id), 4, $f['owner'], 'Team source');
        $allocations->reconcile($second, $allocations->employeeAccount($sourceEmployee->id), 5, $f['owner'], 'Second source');
        $beforeInventory = ProductInventory::query()->whereKey([$f['inventory']->id, $second->id])->orderBy('id')->get()->map->only(['available_quantity', 'reserved_quantity', 'damaged_quantity'])->all();
        $beforeBalances = InventoryAllocationBalance::query()->orderBy('id')->get()->map->only(['account_id', 'product_inventory_id', 'allocated_quantity', 'reserved_quantity'])->all();

        $request = $this->createRequest($f['owner'], StockRequestPurpose::PermanentTransfer, [
            new StockRequestItemData($f['inventory']->id, 6),
            new StockRequestItemData($second->id, 2),
        ]);

        $this->assertMatchesRegularExpression('/^SR-\d{4}-\d{6}$/', $request->reference);
        $this->assertCount(2, $request->items);
        $this->assertCount(3, $request->sourceLines);
        $first = $request->items->firstWhere('product_inventory_id', $f['inventory']->id);
        $this->assertSame(6, collect($first->proposed_sources)->sum('proposed_quantity'));
        $this->assertSame(3, $first->system_unassigned_quantity);
        $this->assertNotContains('system', collect($first->proposed_sources)->pluck('type')->all());
        $this->assertSame($beforeInventory, ProductInventory::query()->whereKey([$f['inventory']->id, $second->id])->orderBy('id')->get()->map->only(['available_quantity', 'reserved_quantity', 'damaged_quantity'])->all());
        $this->assertSame($beforeBalances, InventoryAllocationBalance::query()->orderBy('id')->get()->map->only(['account_id', 'product_inventory_id', 'allocated_quantity', 'reserved_quantity'])->all());
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_for_order_requires_an_accessible_order_and_permanent_transfer_rejects_one(): void
    {
        $f = $this->responsibilityFoundation(5);
        $this->allocateToEmployee($f['inventory'], $f['employee']->id, 5, $f['owner']);
        $order = $this->order($f['owner'], $f['inventory']->warehouse);

        try {
            $this->createRequest($f['owner'], StockRequestPurpose::ForOrder, [new StockRequestItemData($f['inventory']->id, 1)]);
            $this->fail('For Order must require an Order.');
        } catch (ValidationException $exception) {
            $this->assertSame('Please select the Order this stock is required for.', $exception->errors()['order_id'][0]);
        }

        $forOrder = $this->createRequest($f['owner'], StockRequestPurpose::ForOrder, [new StockRequestItemData($f['inventory']->id, 1)], $order->id);
        $this->assertSame($order->id, $forOrder->order_id);

        $this->expectException(ValidationException::class);
        $this->createRequest($f['owner'], StockRequestPurpose::PermanentTransfer, [new StockRequestItemData($f['inventory']->id, 1)], $order->id);
    }

    public function test_responsibility_limits_staff_search_and_direct_creation(): void
    {
        $f = $this->responsibilityFoundation(5);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $staff = $f['employee']->user;
        $this->allocateToEmployee($f['inventory'], $f['employee']->id, 3, $f['owner']);
        $otherBrand = ProductBrand::factory()->create();
        $other = ProductInventory::factory()->create([
            'product_id' => Product::factory(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]),
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 4,
        ]);
        $this->allocateToEmployee($other, Employee::factory()->create()->id, 4, $f['owner']);

        $results = app(StockRequestService::class)->searchInventories($staff, $f['product']->sku);
        $this->assertTrue($results->contains('id', $f['inventory']->id));
        $this->assertFalse(app(StockRequestService::class)->searchInventories($staff, $other->product->sku)->contains('id', $other->id));

        $this->expectException(AuthorizationException::class);
        $this->createRequest($staff, StockRequestPurpose::PermanentTransfer, [new StockRequestItemData($other->id, 1)]);
    }

    public function test_multi_holder_calculation_excludes_system_and_validates_exact_row_atomically(): void
    {
        $f = $this->responsibilityFoundation(10);
        $second = ProductInventory::factory()->create([
            'product_id' => Product::factory(), 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 2,
        ]);
        $source = Employee::factory()->create(['status' => true]);
        $team = Team::query()->create(['name' => 'Sales Team', 'status' => true]);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->ensureShadowCoverage($second, $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($source->id), 2, $f['owner'], 'Employee holder');
        $allocations->reconcile($f['inventory'], $allocations->teamAccount($team->id), 3, $f['owner'], 'Team holder');
        $allocations->reconcile($second, $allocations->employeeAccount($source->id), 1, $f['owner'], 'Second holder');
        $availability = app(StockRequestService::class)->sourceAvailability($f['inventory']->id);
        $this->assertSame(5, $availability['transferable_available']);
        $this->assertSame(5, $availability['system_unassigned']);
        $this->assertCount(2, $availability['holders']);

        try {
            $this->createRequest($f['owner'], StockRequestPurpose::PermanentTransfer, [
                new StockRequestItemData($second->id, 1),
                new StockRequestItemData($f['inventory']->id, 11),
            ]);
            $this->fail('Quantity above transferable allocation must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.1.quantity', $exception->errors());
            $this->assertStringContainsString($f['product']->sku, $exception->errors()['items.1.quantity'][0]);
            $this->assertStringContainsString('Employee/Team and System / Unassigned', $exception->errors()['items.1.quantity'][0]);
            $this->assertStringNotContainsString('SQLSTATE', $exception->errors()['items.1.quantity'][0]);
        }
        $this->assertDatabaseCount('stock_requests', 0);
        $this->assertDatabaseCount('stock_request_items', 0);
    }

    public function test_visibility_supports_requester_owner_admin_and_responsibility_scope(): void
    {
        $f = $this->responsibilityFoundation(5);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $requester = $f['employee']->user;
        $this->allocateToEmployee($f['inventory'], $f['employee']->id, 2, $f['owner']);
        $request = $this->createRequest($requester, StockRequestPurpose::PermanentTransfer, [new StockRequestItemData($f['inventory']->id, 1)]);
        ResponsibilityAssignment::query()->where('employee_id', $f['employee']->id)->update(['status' => 'inactive', 'ended_at' => now()]);
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $scoped = $this->responsibilityUser(EmployeeRole::Manager);
        $unrelated = $this->responsibilityUser(EmployeeRole::Staff);
        $scopedFoundation = $f;
        $scopedFoundation['employee'] = $scoped->employee;
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($scopedFoundation), $f['owner']);

        $this->assertTrue(app(StockRequestService::class)->visibleQuery($requester)->whereKey($request)->exists());
        $this->assertTrue(app(StockRequestService::class)->visibleQuery($f['owner'])->whereKey($request)->exists());
        $this->assertTrue(app(StockRequestService::class)->visibleQuery($admin)->whereKey($request)->exists());
        $this->assertTrue(app(StockRequestService::class)->visibleQuery($scoped)->whereKey($request)->exists());
        $this->assertFalse(app(StockRequestService::class)->visibleQuery($unrelated)->whereKey($request)->exists());
    }

    public function test_duplicate_submission_returns_same_request_without_duplicate_reference_or_items(): void
    {
        $f = $this->responsibilityFoundation(4);
        $this->allocateToEmployee($f['inventory'], $f['employee']->id, 2, $f['owner']);
        $key = (string) Str::uuid();
        $data = new CreateStockRequestData(StockRequestPurpose::PermanentTransfer, null, [new StockRequestItemData($f['inventory']->id, 1)], 'Permanent ownership requirement', $key);

        $first = app(StockRequestService::class)->create($data, $f['owner']);
        $second = app(StockRequestService::class)->create($data, $f['owner']);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('stock_requests', 1);
        $this->assertDatabaseCount('stock_request_items', 1);
    }

    public function test_filament_form_surfaces_readable_required_field_errors_and_preserves_data(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->actingAs($owner);

        Livewire::test(CreateStockRequest::class)
            ->fillForm([
                'purpose' => StockRequestPurpose::ForOrder->value,
                'reason' => '',
                'items' => [['product_inventory_id' => null, 'quantity' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['order_id', 'reason', 'items.0.product_inventory_id', 'items.0.quantity'])
            ->assertSet('data.purpose', StockRequestPurpose::ForOrder->value);

        $this->assertDatabaseCount('stock_requests', 0);
    }

    private function createRequest(User $actor, StockRequestPurpose $purpose, array $items, ?int $orderId = null): StockRequest
    {
        return app(StockRequestService::class)->create(new CreateStockRequestData(
            purpose: $purpose,
            orderId: $orderId,
            items: $items,
            reason: 'Operational stock requirement',
            idempotencyKey: (string) Str::uuid(),
        ), $actor);
    }

    private function allocateToEmployee(ProductInventory $inventory, int $employeeId, int $quantity, User $owner): void
    {
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $service->reconcile($inventory, $service->employeeAccount($employeeId), $quantity, $owner, 'Stock Request test allocation');
    }

    private function order(User $owner, Warehouse $warehouse): Order
    {
        return Order::query()->create([
            'reference' => 'SO-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
            'source' => OrderSource::Manual,
            'status' => OrderStatus::Draft,
            'warehouse_id' => $warehouse->id,
            'order_date' => today(),
            'subtotal' => 0,
            'discount_total' => 0,
            'vat_total' => 0,
            'grand_total' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $owner->id,
        ]);
    }
}
