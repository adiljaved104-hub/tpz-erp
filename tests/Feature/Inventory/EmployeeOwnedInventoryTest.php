<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryPermission;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestSourceStatus;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Pages\Inventory\MyInventory;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ProductInventories\Pages\ViewProductInventory;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequestGrid;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\Team;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryAllocationBreakdownReadService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Inventory\InventoryReadService;
use App\Services\Inventory\StockRequestApprovalService;
use App\Services\Inventory\StockRequestExecutionService;
use App\Services\Inventory\StockRequestService;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Preferences\UserUiPreferenceService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class EmployeeOwnedInventoryTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public static function scopedRoles(): array
    {
        return [[EmployeeRole::Staff], [EmployeeRole::Manager]];
    }

    public static function physicalResponsibilityScopes(): array
    {
        return [['brand'], ['category'], ['product'], ['warehouse'], ['condition'], ['platform']];
    }

    #[DataProvider('physicalResponsibilityScopes')]
    public function test_my_inventory_uses_the_same_physical_responsibility_boundaries_as_stock_management(string $scope): void
    {
        $f = $this->responsibilityFoundation(9);
        $user = $f['employee']->user;
        foreach ([InventoryPermission::View, InventoryPermission::ViewLocationBalances] as $permission) {
            app(EmployeePermissionOverrideService::class)->change($f['employee'], $permission->value, EmployeePermissionEffect::Allow, 'Read-only inventory test', $f['owner']);
        }

        $outsideProduct = Product::factory()->create();
        $outsideWarehouse = Warehouse::factory()->create();
        $outside = ProductInventory::factory()->create([
            'product_id' => $outsideProduct->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 7,
            'reserved_quantity' => 0,
        ]);
        $assignment = ['brandId' => null, 'categoryId' => null, 'productId' => null,
            'warehouseId' => null, 'platformId' => null, 'condition' => null];

        match ($scope) {
            'brand' => $assignment['brandId'] = $f['brand']->id,
            'category' => $assignment['categoryId'] = $f['product']->category_id,
            'product' => $assignment['productId'] = $f['product']->id,
            'warehouse' => $assignment['warehouseId'] = $f['inventory']->warehouse_id,
            'condition' => $assignment['condition'] = ProductCondition::New,
            'platform' => $assignment['platformId'] = $f['platform']->id,
        };

        if ($scope === 'brand') {
            $outsideProduct->update(['brand_id' => ProductBrand::factory()->create()->id]);
        } elseif ($scope === 'category') {
            $outsideProduct->update(['category_id' => ProductCategory::factory()->create()->id]);
        } elseif ($scope === 'condition') {
            $outsideProduct->update(['condition' => ProductCondition::Renewed]);
        } elseif ($scope === 'warehouse') {
            $outside->update(['product_id' => $f['product']->id, 'warehouse_id' => $outsideWarehouse->id]);
        } elseif ($scope === 'platform') {
            $f['inventory']->update(['warehouse_id' => Warehouse::factory()->create([
                'location_type' => InventoryLocationType::MarketplaceFulfilment,
                'marketplace_platform_id' => $f['platform']->id,
            ])->id]);
        } elseif ($scope === 'product') {
            $f['inventory']->update(['available_quantity' => 0]);
        }

        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $assignment), $f['owner']);
        $stockManagementIds = app(InventoryReadService::class)->inventories($user)->pluck('id')->all();
        $this->assertContains($f['inventory']->id, $stockManagementIds);
        $this->assertNotContains($outside->id, $stockManagementIds);
        $this->actingAs($user);
        $myInventoryIds = ProductInventoryResource::getEloquentQuery()->pluck('id')->all();
        $this->assertSame($stockManagementIds, $myInventoryIds);
        if ($scope === 'product') {
            $this->assertSame(0, ProductInventoryResource::getEloquentQuery()->sole()->available_quantity);
        }
        $this->assertTrue($user->can('view', $f['inventory']));
        $this->assertFalse($user->can('view', $outside));
    }

    #[DataProvider('scopedRoles')]
    public function test_operational_scope_displays_authorized_stock_without_claiming_or_exposing_another_holder_allocation(EmployeeRole $role): void
    {
        $f = $this->foundation($role, operationalPhysicalScope: true);
        $user = $f['employee']->user;
        $before = InventoryAllocationBalance::query()->get()->toArray();
        $this->assertTrue(app(ResponsibilityReadService::class)->myInventory($user)->isEmpty());
        $this->assertSame([$f['product']->id], app(ResponsibilityReadService::class)->myInventory($user, ownedOnly: false)->pluck('product_id')->all());
        $this->assertTrue(app(InventoryLocationOverviewService::class)->forUser($user)->isEmpty());
        Livewire::actingAs($user)->test(MyInventory::class)->assertSee('No stock is currently allocated to you.');
        Livewire::test(InventoryOverview::class)->assertDontSee($f['product']->sku)->assertSee('No stock is currently allocated to you.');
        $this->actingAs($user);
        $this->assertTrue(app(ResponsibilityProductScopeService::class)->canAccessInventory($user, $f['inventory']->id));
        $this->assertTrue(app(EmployeeOwnedInventoryReadService::class)->locationVisibleInventoryIds($user)->whereKey($f['inventory']->id)->exists());
        $record = ProductInventoryResource::getEloquentQuery()->sole();
        $this->assertSame(10, $record->available_quantity);
        $this->assertSame(0, $record->my_allocated_quantity);
        $this->assertSame(1, $record->authorized_stock_visible);
        $this->assertSame([], app(InventoryAllocationBreakdownReadService::class)->stockHolderLines($user, $record)->all());
        Livewire::test(ListProductInventories::class)->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$f['inventory']])->assertDontSee($f['holder']->employee->name);
        $this->assertTrue($user->can('view', $f['inventory']));
        $this->assertSame([$f['inventory']->id], app(StockRequestService::class)->searchInventories($user, $f['product']->sku)->modelKeys());
        Livewire::test(CreateStockRequestGrid::class)->assertSee($f['product']->name)->assertSee($f['holder']->employee->name)->assertSeeHtml('data-stock-request-sources');
        $request = app(StockRequestService::class)->create(new CreateStockRequestData(
            StockRequestPurpose::PermanentTransfer, null, [new StockRequestItemData($f['inventory']->id, 2)],
            'Stock needed for shared operations', (string) Str::uuid(),
        ), $user);
        $this->assertSame($f['holder']->employee->name, $request->sourceLines->sole()->account->name);
        $this->assertSame($before, InventoryAllocationBalance::query()->get()->toArray());
        app(StockRequestApprovalService::class)->decide($request->sourceLines->sole(), StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $f['owner']);
        $this->assertSame($before, InventoryAllocationBalance::query()->get()->toArray());
        app(StockRequestExecutionService::class)->execute($request->refresh(), (string) Str::uuid(), $f['owner']);
        $this->assertSame(2, app(ResponsibilityReadService::class)->myInventory($user)->sole()->my_allocated);
        $this->actingAs($user);
        $afterTransfer = ProductInventoryResource::getEloquentQuery()->sole();
        $this->assertSame(10, $afterTransfer->available_quantity);
        $this->assertSame(2, $afterTransfer->my_allocated_quantity);
        $this->assertSame(10, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(8, $f['holderAccount']->balances()->sole()->allocated_quantity);
    }

    #[DataProvider('scopedRoles')]
    public function test_allocated_and_location_overview_stay_own_only_while_my_inventory_labels_authorized_physical_stock(EmployeeRole $role): void
    {
        $f = $this->foundation($role, 8);
        $user = $f['employee']->user;
        app(InventoryAllocationService::class)->reconcile($f['inventory'], app(InventoryAllocationService::class)->employeeAccount($f['employee']->id), 2, $f['owner'], 'Explicit own stock');
        $this->assertSame(2, app(ResponsibilityReadService::class)->myInventory($user)->sole()->my_allocated);
        $this->assertSame(2, app(InventoryLocationOverviewService::class)->forUser($user)->sole()['available']);
        $this->actingAs($user);
        $projection = ProductInventoryResource::getEloquentQuery()->firstOrFail();
        $this->assertSame(10, $projection->available_quantity);
        $this->assertSame(10, $projection->allocated_quantity);
        $this->assertSame(2, $projection->my_allocated_quantity);
        Livewire::actingAs($user)->test(MyInventory::class)
            ->assertViewHas('summary', fn ($summary) => $summary['usable'] === 2)
            ->assertDontSee('Other Allocated')->assertDontSee('System / Unallocated');
        Livewire::test(InventoryOverview::class)->assertDontSee('Damaged')->assertDontSee('QC Pending')->assertDontSee('In Transit');
        Livewire::test(ListProductInventories::class)->assertTableColumnStateSet('available_quantity', 10, $f['inventory'])
            ->assertTableColumnDoesNotExist('damaged_quantity')->assertTableColumnDoesNotExist('average_cost');
    }

    #[DataProvider('scopedRoles')]
    public function test_owned_record_remains_viewable_after_responsibility_ends_without_granting_operational_authority(EmployeeRole $role): void
    {
        $f = $this->foundation($role, 8);
        $user = $f['employee']->user;
        $allocation = app(InventoryAllocationService::class);
        $allocation->reconcile($f['inventory'], $allocation->employeeAccount($f['employee']->id), 2, $f['owner'], 'Explicit employee ownership');
        $unowned = ProductInventory::factory()->create(['available_quantity' => 4]);
        app(EmployeePermissionOverrideService::class)->change($f['employee'], InventoryPermission::Reserve->value, EmployeePermissionEffect::Allow, 'Verify operational scope is still enforced', $f['owner']);
        $assignment = ResponsibilityAssignment::query()->active()->where('employee_id', $f['employee']->id)->sole();
        app(DeactivateResponsibilityAssignment::class)->handle($assignment, new DeactivateResponsibilityAssignmentData('Responsibility changed; ownership remains'), $f['owner']);

        $this->assertSame(0, ResponsibilityAssignment::query()->active()->where('employee_id', $f['employee']->id)->count());
        $this->assertTrue($user->can('view', $f['inventory']));
        $this->assertFalse($user->can('view', $unowned));
        Livewire::actingAs($user)->test(ListProductInventories::class)->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$f['inventory']])->assertCanNotSeeTableRecords([$unowned]);
        Livewire::test(ViewProductInventory::class, ['record' => $f['inventory']->id])->assertOk()
            ->assertSee($f['product']->sku)->assertDontSee('Inventory Balance')->assertDontSee('Location Balances');
        try {
            Livewire::test(ViewProductInventory::class, ['record' => $unowned->id]);
            $this->fail('A non-owned record must not resolve through the view page.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(ProductInventory::class, $exception->getModel());
        }
        $this->assertOwnedQuantity($user, $f['inventory'], 2);
        $authorization = app(InventoryAuthorization::class);
        $this->assertTrue($authorization->allows($user, InventoryPermission::Reserve));
        $this->assertFalse($authorization->allows($user, InventoryPermission::Reserve, $f['inventory']));
        $this->assertSame([], app(StockRequestService::class)->searchInventories($user, $f['product']->sku)->modelKeys());
    }

    public function test_owned_stock_is_summarized_across_main_and_marketplace_locations_without_team_ownership_inference(): void
    {
        $f = $this->foundation(EmployeeRole::Staff, 8);
        $allocation = app(InventoryAllocationService::class);
        $allocation->reconcile($f['inventory'], $allocation->employeeAccount($f['employee']->id), 2, $f['owner'], 'Own Main stock');
        $fba = ProductInventory::factory()->create([
            'product_id' => $f['product']->id, 'available_quantity' => 7, 'reserved_quantity' => 0,
            'warehouse_id' => Warehouse::factory()->create(['location_type' => InventoryLocationType::MarketplaceFulfilment, 'marketplace_platform_id' => $f['platform']->id])->id,
        ]);
        $allocation->ensureShadowCoverage($fba, $f['owner']);
        $allocation->reconcile($fba, $allocation->employeeAccount($f['employee']->id), 3, $f['owner'], 'Own FBA stock');
        $team = Team::query()->create(['name' => 'Shared Team', 'status' => true]);
        $f['employee']->update(['team_id' => $team->id]);
        $allocation->reconcile($fba, $allocation->teamAccount($team->id), 4, $f['owner'], 'Shared Team is not employee ownership');
        $user = $f['employee']->user->fresh();
        $this->assertSame(5, app(InventoryLocationOverviewService::class)->forUser($user)->sole()['total_owned']);
        $this->assertSame(5, app(ResponsibilityReadService::class)->myInventory($user)->sum('my_allocated'));
        $this->assertSame(5, app(EmployeeOwnedInventoryReadService::class)->inventories($user)->get()->sum('available_quantity'));
    }

    public function test_owner_admin_retain_company_reads_and_financial_projection_stays_owner_only(): void
    {
        $f = $this->foundation();
        foreach ([$f['owner'], $this->responsibilityUser(EmployeeRole::Admin)] as $user) {
            $this->actingAs($user);
            $this->assertSame(10, ProductInventoryResource::getEloquentQuery()->firstOrFail()->available_quantity);
            $this->assertSame(10, app(InventoryLocationOverviewService::class)->forUser($user)->sole()['available']);
            $this->assertSame($user->employee->role === EmployeeRole::Owner, array_key_exists('average_cost', ProductInventoryResource::getEloquentQuery()->firstOrFail()->getAttributes()));
        }
    }

    public function test_own_reserved_quantity_and_quantity_cap_remain_independent_of_other_holders(): void
    {
        $f = $this->foundation(EmployeeRole::Staff, 8);
        $allocation = app(InventoryAllocationService::class);
        $account = $allocation->employeeAccount($f['employee']->id);
        $allocation->reconcile($f['inventory'], $account, 2, $f['owner'], 'Own stock');
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $f['inventory']->id, 'product_id' => $f['product']->id,
            'warehouse_id' => $f['inventory']->warehouse_id, 'quantity' => 1,
        ]);
        DB::transaction(function () use ($f, $allocation, $reservation, $account): void {
            $f['inventory']->increment('reserved_quantity');
            $allocation->reserveExact($reservation, [$account->id => 1], $f['owner']);
        });
        $user = $f['employee']->user;
        $row = app(ResponsibilityReadService::class)->myInventory($user)->sole();
        $this->assertSame(2, $row->my_allocated);
        $this->assertSame(1, $row->my_reserved);
        $this->assertSame(1, $row->employee_usable);
        $overview = app(InventoryLocationOverviewService::class)->forUser($user)->sole();
        $this->assertSame(2, $overview['available']);
        $this->assertSame(1, $overview['reserved']);
        $this->assertSame(1, $overview['sellable']);
        $this->actingAs($user);
        $record = ProductInventoryResource::getEloquentQuery()->sole();
        $this->assertSame(10, $record->available_quantity);
        $this->assertSame(1, $record->reserved_quantity);
        $this->assertSame(9, $record->sellableQuantity());
        $this->assertSame(2, $record->my_allocated_quantity);
        $this->assertSame(1, $record->my_reserved_quantity);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 1]), $f['owner']);
        Livewire::actingAs($user)->test(MyInventory::class)->assertViewHas('summary', fn ($summary) => $summary['usable'] === 1);
        $this->assertSame(10, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(8, $f['holderAccount']->balances()->sole()->allocated_quantity);
    }

    public function test_inventory_menu_renames_preserve_legacy_navigation_preferences(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->actingAs($owner);
        $this->assertSame('Inventory Record', ProductInventoryResource::getModelLabel());
        $this->assertSame('My Inventory', ProductInventoryResource::getPluralModelLabel());
        $this->assertSame('My Inventory', ProductInventoryResource::getNavigationLabel());
        $this->assertSame('My Allocated Stock', MyInventory::getNavigationLabel());
        $this->assertSame('Inventory by Location', InventoryOverview::getNavigationLabel());
        $path = trim(parse_url(ProductInventoryResource::getUrl(), PHP_URL_PATH), '/');
        $legacyKeys = collect(['Stock by Location', 'Location Balances'])
            ->map(fn (string $label): string => 'item:'.hash('sha256', implode('|', ['Inventory', '', $label, $path])))
            ->all();
        app(UserUiPreferenceService::class)->put($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, $legacyKeys);
        $groups = [NavigationGroup::make('Inventory')->items([
            NavigationItem::make('My Inventory')->url(ProductInventoryResource::getUrl()),
            NavigationItem::make('My Allocated Stock')->url('/admin/my-inventory'),
        ])];

        $result = app(NavigationPreferenceService::class)->apply($owner, $groups);

        $this->assertSame(['My Allocated Stock'], collect($result[0]->getItems())->map(fn ($item) => $item->getLabel())->all());
        $this->assertSame($legacyKeys, app(UserUiPreferenceService::class)->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
    }

    public function test_hidden_preferences_for_old_my_inventory_and_overview_labels_still_hide_renamed_pages(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $pathLabels = [
            ['admin/my-inventory', 'My Inventory'],
            ['admin/inventory-overview', 'Inventory Overview'],
        ];
        $legacyKeys = collect($pathLabels)->map(fn (array $pair): string => 'item:'.hash(
            'sha256', implode('|', ['Inventory', '', $pair[1], $pair[0]]),
        ))->all();
        app(UserUiPreferenceService::class)->put($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, $legacyKeys);

        $groups = [NavigationGroup::make('Inventory')->items([
            NavigationItem::make('My Allocated Stock')->url('/admin/my-inventory'),
            NavigationItem::make('Inventory by Location')->url('/admin/inventory-overview'),
        ])];
        $result = app(NavigationPreferenceService::class)->apply($owner, $groups);

        $this->assertSame([], collect($result)->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())->all());
        $this->assertSame($legacyKeys, app(UserUiPreferenceService::class)->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
    }

    private function assertOwnedQuantity($user, ProductInventory $inventory, int $quantity): void
    {
        $row = app(ResponsibilityReadService::class)->myInventory($user)->sole();
        $this->assertSame($quantity, $row->my_allocated);
        $this->assertSame($quantity, $row->available);
        $this->assertSame($quantity, $row->employee_usable);
        $this->assertSame($quantity, app(InventoryLocationOverviewService::class)->forUser($user)->sole()['total_owned']);
        $this->actingAs($user);
        $this->assertSame($quantity, ProductInventoryResource::getEloquentQuery()->firstOrFail()->available_quantity);
    }

    private function foundation(EmployeeRole $role = EmployeeRole::Staff, int $held = 10, bool $operationalPhysicalScope = false): array
    {
        $f = $this->responsibilityFoundation(10);
        $f['employee']->update(['role' => $role]);
        foreach ([InventoryPermission::View->value, InventoryPermission::ViewLocationBalances->value, InventoryLocationPermission::View->value] as $permission) {
            app(EmployeePermissionOverrideService::class)->change($f['employee'], $permission, EmployeePermissionEffect::Allow, 'Owned view access test', $f['owner']);
        }
        $f['product']->update(['condition' => ProductCondition::New, 'name' => 'Full detailed laptop title including memory, processor, display and specifications']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'categoryId' => $f['product']->category_id,
            'platformId' => $operationalPhysicalScope ? null : $f['platform']->id,
            'condition' => ProductCondition::New,
        ]), $f['owner']);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($f['inventory'], $f['owner']);
        $holderAccount = $allocation->employeeAccount($holder->employee->id);
        $allocation->reconcile($f['inventory'], $holderAccount, $held, $f['owner'], 'Other holder stock');

        return $f + compact('holder', 'holderAccount');
    }
}
