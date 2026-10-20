<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryPermission;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment as CreateResponsibilityAssignmentPage;
use App\Filament\Resources\WarehouseStockVisibilityGrants\Pages\CreateWarehouseStockVisibilityGrant;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\Warehouse;
use App\Models\WarehouseStockVisibilityGrant;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryAllocationBreakdownReadService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\WarehouseStockActionScopeService;
use App\Services\Responsibilities\WarehouseStockVisibilityGrantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class WarehouseConditionVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_shared_visibility_grants_multiple_employees_condition_scoped_physical_rows_and_other_holders_only_on_stock_by_location(): void
    {
        $f = $this->responsibilityFoundation(10);
        $viewer = $this->responsibilityUser(EmployeeRole::Manager);
        $secondViewer = $this->responsibilityUser(EmployeeRole::Staff);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $viewer->employee->update(['employee_id' => 'TPZ-0013', 'name' => 'Shafaat Ullah']);
        $viewer->refresh();
        $f['product']->update(['condition' => ProductCondition::Renewed]);

        $usedProduct = Product::factory()->create([
            'condition' => ProductCondition::Used,
            'brand_id' => $f['brand']->id,
            'category_id' => $f['product']->category_id,
        ]);
        $usedInventory = ProductInventory::factory()->create([
            'product_id' => $usedProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 2,
        ]);
        $otherWarehouse = Warehouse::factory()->create(['location_type' => InventoryLocationType::CompanyWarehouse]);
        $otherLocationInventory = ProductInventory::factory()->create([
            'product_id' => $f['product']->id,
            'warehouse_id' => $otherWarehouse->id,
            'available_quantity' => 3,
        ]);

        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($f['inventory'], $f['owner']);
        $holderAccount = $allocation->employeeAccount($holder->employee->id);
        $allocation->reconcile($f['inventory'], $holderAccount, 6, $f['owner'], 'Existing employee ownership');
        $ledgerBefore = InventoryAllocationBalance::query()->orderBy('id')->get()->toArray();
        $operationalCountBefore = ResponsibilityAssignment::query()->count();

        $this->grant($f['owner'], $viewer->employee->id, $f['inventory']->warehouse_id, ProductCondition::Renewed->value);
        $this->grant($f['owner'], $secondViewer->employee->id, $f['inventory']->warehouse_id, ProductCondition::Renewed->value);
        foreach ([InventoryPermission::View, InventoryPermission::ViewLocationBalances] as $permission) {
            app(EmployeePermissionOverrideService::class)->change($secondViewer->employee, $permission->value, EmployeePermissionEffect::Allow, 'Shared read-only visibility still requires both inventory view permissions', $f['owner']);
        }

        $this->assertSame($operationalCountBefore, ResponsibilityAssignment::query()->count());
        $this->assertSame(2, WarehouseStockVisibilityGrant::query()->active()->count());
        $this->assertSame($ledgerBefore, InventoryAllocationBalance::query()->orderBy('id')->get()->toArray());

        $this->actingAs($viewer);
        $visibleIds = app(EmployeeOwnedInventoryReadService::class)->locationInventories($viewer)->pluck('id')->all();
        $this->assertSame([$f['inventory']->id], $visibleIds);
        $this->assertTrue($viewer->can('view', $f['inventory']));
        $this->assertTrue($secondViewer->can('view', $f['inventory']));
        $this->assertFalse($viewer->can('view', $usedInventory));
        $this->assertFalse($viewer->can('view', $otherLocationInventory));

        Livewire::actingAs($viewer)->test(ListProductInventories::class)
            ->assertCountTableRecords(1)->assertCanSeeTableRecords([$f['inventory']])
            ->assertCanNotSeeTableRecords([$usedInventory, $otherLocationInventory]);

        $holderLines = app(InventoryAllocationBreakdownReadService::class)->stockHolderLines($viewer, $f['inventory']);
        $this->assertTrue($holderLines->contains(fn (array $line): bool => str_contains($line['label'], $holder->employee->name) && $line['allocated'] === 6));
        $this->assertTrue($holderLines->contains(fn (array $line): bool => $line['is_system']));
        $secondVisibleIds = app(EmployeeOwnedInventoryReadService::class)->locationInventories($secondViewer)->pluck('id')->all();
        $this->assertSame([$f['inventory']->id], $secondVisibleIds);

        // Read visibility is not My Inventory ownership and did not change ledger balances.
        $this->assertTrue(app(EmployeeOwnedInventoryReadService::class)->inventories($viewer)->get()->isEmpty());
        $this->assertSame($ledgerBefore, InventoryAllocationBalance::query()->orderBy('id')->get()->toArray());
    }

    public function test_read_only_visibility_does_not_weaken_operational_conflicts_or_stock_action_authorization(): void
    {
        $f = $this->responsibilityFoundation(10);
        $f['product']->update(['condition' => ProductCondition::Renewed]);
        $firstOperator = $this->responsibilityUser(EmployeeRole::Staff);
        $viewer = $this->responsibilityUser(EmployeeRole::Manager);

        $operational = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $firstOperator->employee->id,
            'brandId' => null,
            'categoryId' => $f['product']->category_id,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);

        $grant = $this->grant($f['owner'], $viewer->employee->id, $f['inventory']->warehouse_id, ProductCondition::Renewed->value);
        $this->assertDatabaseHas('responsibility_assignments', ['id' => $operational->id, 'status' => 'active']);
        $this->assertDatabaseHas('warehouse_stock_visibility_grants', ['id' => $grant->id, 'status' => 'active']);

        try {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
                'employeeId' => $viewer->employee->id,
                'brandId' => null,
                'categoryId' => $f['product']->category_id,
                'condition' => ProductCondition::Renewed,
            ]), $f['owner']);
            $this->fail('Overlapping operational responsibilities must continue to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope', $exception->errors());
        }

        $overrides = app(EmployeePermissionOverrideService::class);
        foreach ([InventoryPermission::AdjustStock, InventoryPermission::Reserve, InventoryPermission::ManageAllocations] as $permission) {
            $overrides->change($viewer->employee, $permission->value, EmployeePermissionEffect::Allow, 'Permission is not a warehouse visibility capability', $f['owner']);
        }

        $authorization = app(InventoryAuthorization::class);
        $this->assertTrue($viewer->can('view', $f['inventory']));
        $this->assertFalse(app(ResponsibilityProductScopeService::class)->canAccessInventory($viewer, $f['inventory']->id));
        foreach ([InventoryPermission::AdjustStock, InventoryPermission::Reserve, InventoryPermission::ManageAllocations] as $permission) {
            $this->assertFalse($authorization->allows($viewer, $permission, $f['inventory']), $permission->value.' must still require operational scope.');
        }
        $this->assertFalse($viewer->can('update', $f['inventory']));
    }

    public function test_both_inventory_view_permissions_are_required_and_grant_can_be_revoked_without_deleting_history(): void
    {
        $f = $this->responsibilityFoundation(5);
        $viewer = $this->responsibilityUser(EmployeeRole::Manager);
        $f['product']->update(['condition' => ProductCondition::Renewed]);
        $grant = $this->grant($f['owner'], $viewer->employee->id, $f['inventory']->warehouse_id, ProductCondition::Renewed->value);

        $overrides = app(EmployeePermissionOverrideService::class);
        $overrides->change($viewer->employee, InventoryPermission::ViewLocationBalances->value, EmployeePermissionEffect::Deny, 'Location permission must remain required', $f['owner']);
        $this->actingAs($viewer);
        $this->assertFalse($viewer->can('viewAny', ProductInventory::class));
        $this->assertSame([], app(EmployeeOwnedInventoryReadService::class)->locationInventories($viewer)->pluck('id')->all());

        $overrides->change($viewer->employee, InventoryPermission::ViewLocationBalances->value, EmployeePermissionEffect::Allow, 'Restore location permission for test', $f['owner']);
        $overrides->change($viewer->employee, InventoryPermission::View->value, EmployeePermissionEffect::Deny, 'Inventory View must remain required', $f['owner']);
        $this->assertFalse($viewer->can('viewAny', ProductInventory::class));
        $this->assertFalse($viewer->can('view', $f['inventory']));

        $service = app(WarehouseStockVisibilityGrantService::class);
        $service->deactivate($grant, 'Scope ended', $f['owner']);
        $this->assertDatabaseHas('warehouse_stock_visibility_grants', [
            'id' => $grant->id,
            'status' => 'inactive',
            'active_fingerprint' => null,
            'ended_by_user_id' => $f['owner']->id,
        ]);
        $this->assertFalse($viewer->can('view', $f['inventory']));
        $this->assertSame(1, WarehouseStockVisibilityGrant::query()->count());
    }

    public function test_existing_unrestricted_operational_warehouse_responsibility_keeps_its_current_visibility(): void
    {
        $f = $this->responsibilityFoundation(6);
        $anas = $this->responsibilityUser(EmployeeRole::Manager);
        $usedProduct = Product::factory()->create([
            'condition' => ProductCondition::Used,
            'brand_id' => $f['brand']->id,
            'category_id' => $f['product']->category_id,
        ]);
        $usedInventory = ProductInventory::factory()->create([
            'product_id' => $usedProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 4,
        ]);

        app(CreateResponsibilityAssignment::class)->handle(new CreateResponsibilityAssignmentData(
            employeeId: $anas->employee->id,
            mode: ResponsibilityAssignmentMode::Scope,
            brandId: null,
            platformId: null,
            productId: null,
            productInventoryId: null,
            assignedQuantity: null,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Existing unrestricted warehouse visibility',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
            warehouseId: $f['inventory']->warehouse_id,
        ), $f['owner']);

        $this->actingAs($anas);
        $this->assertEqualsCanonicalizing(
            [$f['inventory']->id, $usedInventory->id],
            app(EmployeeOwnedInventoryReadService::class)->locationInventories($anas)->pluck('id')->all(),
        );
        $gridRows = Livewire::actingAs($anas)->test(StockAdjustments::class)->instance()->rows()->getCollection();
        $this->assertEqualsCanonicalizing([$f['inventory']->id, $usedInventory->id], $gridRows->pluck('id')->all());
    }

    public function test_shared_visibility_duplicate_and_operational_scope_errors_are_shown_in_the_form(): void
    {
        $f = $this->responsibilityFoundation(10);
        $f['product']->update(['condition' => ProductCondition::Renewed]);
        $operator = $this->responsibilityUser(EmployeeRole::Staff);
        $newEmployee = $this->responsibilityUser(EmployeeRole::Manager);

        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $operator->employee->id,
            'brandId' => null,
            'categoryId' => $f['product']->category_id,
            'condition' => ProductCondition::Renewed,
        ]), $f['owner']);

        $this->actingAs($f['owner']);
        $component = Livewire::test(CreateResponsibilityAssignmentPage::class)
            ->assertSee('Scope validation')
            ->fillForm([
                'employee_id' => $newEmployee->employee->id,
                'scope_type' => 'condition_category',
                'category_ids' => [$f['product']->category_id],
                'condition' => ProductCondition::Renewed->value,
                'effective_at' => now()->subMinute()->toDateTimeString(),
                'reason' => 'Preserve operational conflict checks',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->call('create');
        $component->assertHasFormErrors(['scope'])->assertSee('overlapping operational responsibility');

        $grant = $this->grant($f['owner'], $newEmployee->employee->id, $f['inventory']->warehouse_id, ProductCondition::Renewed->value);
        Livewire::test(CreateWarehouseStockVisibilityGrant::class)
            ->fillForm([
                'employee_ids' => [$newEmployee->employee->id],
                'warehouse_id' => $f['inventory']->warehouse_id,
                'product_conditions' => [ProductCondition::Renewed->value],
                'effective_at' => now()->subMinute()->toDateTimeString(),
                'reason' => 'Duplicate visibility scope',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->call('create')
            ->assertHasFormErrors(['scope'])
            ->assertSee('exact shared warehouse visibility scope is already active');
        $this->assertDatabaseHas('warehouse_stock_visibility_grants', ['id' => $grant->id, 'status' => 'active']);
    }

    public function test_grant_batch_supports_multiple_employees_and_conditions_without_enabling_actions_by_default(): void
    {
        $f = $this->responsibilityFoundation(10);
        $first = $this->responsibilityUser(EmployeeRole::Manager);
        $second = $this->responsibilityUser(EmployeeRole::Staff);

        $grants = app(WarehouseStockVisibilityGrantService::class)->createMany([
            'employee_ids' => [$first->employee->id, $second->employee->id],
            'warehouse_id' => $f['inventory']->warehouse_id,
            'product_conditions' => ['all'],
            'effective_at' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Shared read scope for the full warehouse range',
            'idempotency_key' => (string) Str::uuid(),
        ], $f['owner']);

        $this->assertCount(10, $grants);
        $this->assertSame(10, WarehouseStockVisibilityGrant::query()->active()->count());
        $this->assertEqualsCanonicalizing(
            collect(ProductCondition::cases())->map(fn (ProductCondition $condition): string => $condition->value)->all(),
            $grants->pluck('product_condition')->unique()->all(),
        );
        $this->assertTrue($grants->every(fn (WarehouseStockVisibilityGrant $grant): bool => $grant->view_stock));
        $this->assertTrue($grants->every(fn (WarehouseStockVisibilityGrant $grant): bool => $grant->view_employee_stock_holders));
        $this->assertTrue($grants->every(fn (WarehouseStockVisibilityGrant $grant): bool => ! $grant->adjust_warehouse_stock && ! $grant->consume_other_employees_stock));
        $this->assertSame(0, ResponsibilityAssignment::query()->count());
    }

    public function test_holder_visibility_option_hides_other_employee_names_without_hiding_stock_rows(): void
    {
        $f = $this->responsibilityFoundation(10);
        $viewer = $this->responsibilityUser(EmployeeRole::Manager);
        $holder = $this->responsibilityUser(EmployeeRole::Manager);
        $f['product']->update(['condition' => ProductCondition::Renewed]);

        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($holder->employee->id), 4, $f['owner'], 'Existing stock ownership');
        app(WarehouseStockVisibilityGrantService::class)->create([
            'employee_id' => $viewer->employee->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'product_condition' => ProductCondition::Renewed->value,
            'view_stock' => true,
            'view_employee_stock_holders' => false,
            'effective_at' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Warehouse quantity only',
            'idempotency_key' => (string) Str::uuid(),
        ], $f['owner']);

        $read = app(EmployeeOwnedInventoryReadService::class);
        $this->assertSame([$f['inventory']->id], $read->locationInventories($viewer)->pluck('id')->all());
        $breakdown = app(InventoryAllocationBreakdownReadService::class);
        $this->assertSame([], $breakdown->stockHolderLines($viewer, $f['inventory'])->all());
        $this->assertSame([], $breakdown->stockHolderFilterOptions($viewer));
        $this->assertSame([], $breakdown->forInventory($viewer, $f['inventory'])['lines']->all());
        $this->assertNotSame([], $breakdown->stockHolderLines($holder, $f['inventory'])->all());
    }

    public function test_action_scope_requires_the_matching_capability_and_access_control_permission(): void
    {
        $f = $this->responsibilityFoundation(10);
        $actor = $this->responsibilityUser(EmployeeRole::Manager);
        $f['product']->update(['condition' => ProductCondition::Renewed]);
        $this->grantWithCapabilities($f['owner'], $actor->employee->id, $f['inventory']->warehouse_id, ProductCondition::Renewed->value, [
            'adjust_warehouse_stock' => true,
        ]);
        $authorization = app(InventoryAuthorization::class);
        $this->expectException(AuthorizationException::class);
        $authorization->authorizeWarehouseAction($actor, InventoryPermission::AdjustStock, $f['inventory'], 'adjust_warehouse_stock');
    }

    public function test_scoped_adjustment_permission_is_condition_and_warehouse_bounded(): void
    {
        $f = $this->responsibilityFoundation(10);
        $actor = $this->responsibilityUser(EmployeeRole::Manager);
        $f['product']->update(['condition' => ProductCondition::Renewed]);
        $this->grantWithCapabilities($f['owner'], $actor->employee->id, $f['inventory']->warehouse_id, ProductCondition::Renewed->value, [
            'adjust_warehouse_stock' => true,
        ]);
        app(EmployeePermissionOverrideService::class)->change(
            $actor->employee, InventoryPermission::AdjustStock->value, EmployeePermissionEffect::Allow,
            'Explicit Access Control permission paired with the warehouse action scope', $f['owner'],
        );

        $authorization = app(InventoryAuthorization::class);
        $authorization->authorizeWarehouseAction($actor, InventoryPermission::AdjustStock, $f['inventory'], 'adjust_warehouse_stock');
        $this->assertFalse(app(WarehouseStockActionScopeService::class)
            ->allows($actor, $f['inventory'], 'consume_other_employees_stock'));

        $otherWarehouse = Warehouse::factory()->create(['location_type' => InventoryLocationType::CompanyWarehouse]);
        $otherWarehouseInventory = ProductInventory::factory()->create([
            'product_id' => $f['product']->id,
            'warehouse_id' => $otherWarehouse->id,
            'available_quantity' => 2,
        ]);
        $this->assertFalse(app(WarehouseStockActionScopeService::class)
            ->allows($actor, $otherWarehouseInventory, 'adjust_warehouse_stock'));

        $usedProduct = Product::factory()->create([
            'condition' => ProductCondition::Used,
            'brand_id' => $f['brand']->id,
            'category_id' => $f['product']->category_id,
        ]);
        $usedInventory = ProductInventory::factory()->create([
            'product_id' => $usedProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 2,
        ]);
        $this->assertFalse(app(WarehouseStockActionScopeService::class)
            ->allows($actor, $usedInventory, 'adjust_warehouse_stock'));
    }

    private function grant($actor, int $employeeId, int $warehouseId, string $condition): WarehouseStockVisibilityGrant
    {
        return app(WarehouseStockVisibilityGrantService::class)->create([
            'employee_id' => $employeeId,
            'warehouse_id' => $warehouseId,
            'product_condition' => $condition,
            'effective_at' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Shared read-only warehouse visibility',
            'idempotency_key' => (string) Str::uuid(),
        ], $actor);
    }

    /** @param array<string, bool> $capabilities */
    private function grantWithCapabilities($actor, int $employeeId, int $warehouseId, string $condition, array $capabilities): WarehouseStockVisibilityGrant
    {
        return app(WarehouseStockVisibilityGrantService::class)->create([
            'employee_id' => $employeeId,
            'warehouse_id' => $warehouseId,
            'product_condition' => $condition,
            'effective_at' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Explicit condition and warehouse action authorization',
            'idempotency_key' => (string) Str::uuid(),
            ...$capabilities,
        ], $actor);
    }
}
