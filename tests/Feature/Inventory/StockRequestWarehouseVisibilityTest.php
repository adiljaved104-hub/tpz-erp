<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\StockRequestPurpose;
use App\Models\EmployeePermissionOverride;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\StockRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\StockRequestService;
use App\Services\Responsibilities\WarehouseStockVisibilityGrantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockRequestWarehouseVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_shared_renewed_grant_scopes_stock_request_search_holders_and_pending_request_sources(): void
    {
        $f = $this->responsibilityFoundation(6);
        $f['product']->update(['condition' => ProductCondition::Renewed]);
        $shafaat = $this->responsibilityUser(EmployeeRole::Staff);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);

        $this->grantRenewedMainWarehouse($f['owner'], $shafaat, $f['inventory']->warehouse_id);

        $otherBrand = ProductBrand::factory()->create();
        $otherCategory = ProductCategory::factory()->create();
        $otherRenewedProduct = Product::factory()->create([
            'condition' => ProductCondition::Renewed,
            'brand_id' => $otherBrand->id,
            'brand' => $otherBrand->name,
            'category_id' => $otherCategory->id,
            'category' => $otherCategory->name,
        ]);
        $otherRenewedInventory = ProductInventory::factory()->create([
            'product_id' => $otherRenewedProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 2,
        ]);

        $newProduct = Product::factory()->create(['condition' => ProductCondition::New]);
        $newInventory = ProductInventory::factory()->create([
            'product_id' => $newProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 4,
        ]);
        $otherWarehouse = Warehouse::factory()->create(['status' => true]);
        $outsideProduct = Product::factory()->create(['condition' => ProductCondition::Renewed]);
        $outsideInventory = ProductInventory::factory()->create([
            'product_id' => $outsideProduct->id,
            'warehouse_id' => $otherWarehouse->id,
            'available_quantity' => 5,
        ]);

        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $holderAccount = $allocations->employeeAccount($holder->employee->id);
        $allocations->reconcile($f['inventory'], $holderAccount, 6, $f['owner'], 'Existing stock holder');

        $requests = app(StockRequestService::class);
        $this->assertTrue($requests->searchInventories($shafaat, $f['product']->sku)->contains('id', $f['inventory']->id));
        $this->assertTrue($requests->searchInventories($shafaat, $otherRenewedProduct->sku)->contains('id', $otherRenewedInventory->id));
        $this->assertFalse($requests->searchInventories($shafaat, $newProduct->sku)->contains('id', $newInventory->id));
        $this->assertFalse($requests->searchInventories($shafaat, $outsideProduct->sku)->contains('id', $outsideInventory->id));
        $this->assertNull($requests->requestableInventory($shafaat, $newInventory->id));
        $this->assertNull($requests->requestableInventory($shafaat, $outsideInventory->id));

        $availability = $requests->sourceAvailability($shafaat, $f['inventory']->id);
        $this->assertSame($holderAccount->id, $availability['holders']->sole()['account_id']);
        $this->assertStringContainsString($holder->employee->name, $availability['holders']->sole()['label']);
        $this->assertSame(6, $availability['requestable_available']);

        $this->actingAs($shafaat);
        $grid = Livewire::test(\App\Filament\Resources\StockRequests\Pages\CreateStockRequestGrid::class)
            ->set('search', $f['product']->sku);
        $row = $grid->instance()->rows()->getCollection()->sole();
        $this->assertSame($f['inventory']->id, $row->id);
        $this->assertStringContainsString($holder->employee->name, $row->holder_labels[0]);
        $this->assertContains($f['inventory']->warehouse->name, $grid->instance()->filterOptions()['warehouses']);

        $beforeInventory = $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity']);
        $beforeBalances = InventoryAllocationBalance::query()->orderBy('id')->get()->map->only(['account_id', 'product_inventory_id', 'allocated_quantity', 'reserved_quantity'])->all();
        $beforeMovements = StockMovement::query()->count();
        $request = $requests->create(new CreateStockRequestData(
            StockRequestPurpose::PermanentTransfer,
            null,
            [new StockRequestItemData($f['inventory']->id, 2)],
            'Request stock from its original holder',
            (string) Str::uuid(),
        ), $shafaat);

        $this->assertSame('pending', $request->status->value);
        $source = $request->items->sole()->sourceLines->sole();
        $this->assertSame($holderAccount->id, $source->inventory_allocation_account_id);
        $this->assertStringContainsString($holder->employee->name, $source->source_label);
        $this->assertSame($beforeInventory, $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity']));
        $this->assertSame($beforeBalances, InventoryAllocationBalance::query()->orderBy('id')->get()->map->only(['account_id', 'product_inventory_id', 'allocated_quantity', 'reserved_quantity'])->all());
        $this->assertSame($beforeMovements, StockMovement::query()->count());
        $this->assertSame(0, InventoryReservation::query()->count());

        try {
            $requests->create(new CreateStockRequestData(
                StockRequestPurpose::PermanentTransfer,
                null,
                [new StockRequestItemData($newInventory->id, 1)],
                'Attempt to request out-of-scope stock',
                (string) Str::uuid(),
            ), $shafaat);
            $this->fail('A Renewed-only visibility grant must not authorize New stock.');
        } catch (AuthorizationException) {
            $this->assertSame(1, StockRequest::query()->count());
        }

        try {
            $requests->create(new CreateStockRequestData(
                StockRequestPurpose::PermanentTransfer,
                null,
                [new StockRequestItemData($outsideInventory->id, 1)],
                'Attempt to request another warehouse',
                (string) Str::uuid(),
            ), $shafaat);
            $this->fail('A Main Warehouse grant must not authorize another warehouse.');
        } catch (AuthorizationException) {
            $this->assertSame(1, StockRequest::query()->count());
        }
    }

    public function test_shared_warehouse_grant_does_not_replace_create_stock_requests_permission(): void
    {
        $f = $this->responsibilityFoundation(4);
        $f['product']->update(['condition' => ProductCondition::Renewed]);
        $shafaat = $this->responsibilityUser(EmployeeRole::Staff);
        $this->grantRenewedMainWarehouse($f['owner'], $shafaat, $f['inventory']->warehouse_id);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $shafaat->employee->id,
            'permission_key' => InventoryPermission::CreateStockRequests->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $f['owner']->id,
            'reason' => 'Stock request submission remains separately controlled',
        ]);

        $requests = app(StockRequestService::class);
        try {
            $requests->searchInventories($shafaat, $f['product']->sku);
            $this->fail('Shared visibility must not grant Stock Request creation permission.');
        } catch (AuthorizationException) {
            $this->assertSame(0, StockRequest::query()->count());
        }

        try {
            $requests->create(new CreateStockRequestData(
                StockRequestPurpose::PermanentTransfer,
                null,
                [new StockRequestItemData($f['inventory']->id, 1)],
                'Attempt without Stock Request permission',
                (string) Str::uuid(),
            ), $shafaat);
            $this->fail('The backend must enforce Create Stock Requests.');
        } catch (AuthorizationException) {
            $this->assertSame(0, StockRequest::query()->count());
        }
    }

    public function test_existing_unrestricted_anas_style_warehouse_responsibility_still_finds_stock(): void
    {
        $f = $this->responsibilityFoundation(4);
        $anas = $this->responsibilityUser(EmployeeRole::Manager);
        $anas->employee->update(['name' => 'Anas']);
        app(CreateResponsibilityAssignment::class)->handle(new CreateResponsibilityAssignmentData(
            employeeId: $anas->employee->id,
            mode: ResponsibilityAssignmentMode::Scope,
            brandId: null,
            platformId: null,
            productId: null,
            productInventoryId: null,
            assignedQuantity: null,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Existing unrestricted warehouse access',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
            warehouseId: $f['inventory']->warehouse_id,
        ), $f['owner']);

        $results = app(StockRequestService::class)->searchInventories($anas, $f['product']->sku);

        $this->assertTrue($results->contains('id', $f['inventory']->id));
    }

    private function grantRenewedMainWarehouse(User $owner, User $employee, int $warehouseId): void
    {
        app(WarehouseStockVisibilityGrantService::class)->create([
            'employee_id' => $employee->employee->id,
            'warehouse_id' => $warehouseId,
            'product_condition' => ProductCondition::Renewed->value,
            'effective_at' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Authorized Renewed stock request access',
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);
    }
}
