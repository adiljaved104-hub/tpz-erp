<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
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
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequestGrid;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryReservation;
use App\Models\ProductInventory;
use App\Models\Team;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Inventory\StockRequestApprovalService;
use App\Services\Inventory\StockRequestExecutionService;
use App\Services\Inventory\StockRequestService;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Preferences\UserUiPreferenceService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
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

    #[DataProvider('scopedRoles')]
    public function test_broad_shared_scope_never_displays_another_holder_stock_but_can_request_it(EmployeeRole $role): void
    {
        $f = $this->foundation($role);
        $user = $f['employee']->user;
        $before = InventoryAllocationBalance::query()->get()->toArray();
        $this->assertTrue(app(ResponsibilityReadService::class)->myInventory($user)->isEmpty());
        $this->assertSame([$f['product']->id], app(ResponsibilityReadService::class)->myInventory($user, ownedOnly: false)->pluck('product_id')->all());
        $this->assertTrue(app(InventoryLocationOverviewService::class)->forUser($user)->isEmpty());
        Livewire::actingAs($user)->test(MyInventory::class)->assertSee('No stock is currently allocated to you.');
        Livewire::test(InventoryOverview::class)->assertDontSee($f['product']->sku)->assertSee('No stock is currently allocated to you.');
        Livewire::test(ListProductInventories::class)->assertCountTableRecords(0);
        $this->assertFalse($user->can('view', $f['inventory']));
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
        $this->assertOwnedQuantity($user, $f['inventory'], 2);
        $this->assertSame(10, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(8, $f['holderAccount']->balances()->sole()->allocated_quantity);
    }

    #[DataProvider('scopedRoles')]
    public function test_each_owned_view_shows_only_own_balance_and_omits_unattributed_physical_states(EmployeeRole $role): void
    {
        $f = $this->foundation($role, 8);
        $user = $f['employee']->user;
        app(InventoryAllocationService::class)->reconcile($f['inventory'], app(InventoryAllocationService::class)->employeeAccount($f['employee']->id), 2, $f['owner'], 'Explicit own stock');
        $this->assertOwnedQuantity($user, $f['inventory'], 2);
        Livewire::actingAs($user)->test(MyInventory::class)
            ->assertViewHas('summary', fn ($summary) => $summary['usable'] === 2)
            ->assertDontSee('Other Allocated')->assertDontSee('System / Unallocated');
        Livewire::test(InventoryOverview::class)->assertDontSee('Damaged')->assertDontSee('QC Pending')->assertDontSee('In Transit');
        Livewire::test(ListProductInventories::class)->assertTableColumnStateSet('available_quantity', 2, $f['inventory'])
            ->assertTableColumnDoesNotExist('damaged_quantity')->assertTableColumnDoesNotExist('average_cost');
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
        $this->assertSame(2, $record->available_quantity);
        $this->assertSame(1, $record->reserved_quantity);
        $this->assertSame(1, $record->sellableQuantity());
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 1]), $f['owner']);
        Livewire::actingAs($user)->test(MyInventory::class)->assertViewHas('summary', fn ($summary) => $summary['usable'] === 1);
        $this->assertSame(10, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(8, $f['holderAccount']->balances()->sole()->allocated_quantity);
    }

    public function test_stock_by_location_rename_preserves_existing_hidden_navigation_preference(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->actingAs($owner);
        $this->assertSame('Stock by Location', ProductInventoryResource::getPluralModelLabel());
        $path = trim(parse_url(ProductInventoryResource::getUrl(), PHP_URL_PATH), '/');
        $legacyKey = 'item:'.hash('sha256', implode('|', ['Inventory', '', 'Location Balances', $path]));
        app(UserUiPreferenceService::class)->put($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, [$legacyKey]);
        $groups = [NavigationGroup::make('Inventory')->items([
            NavigationItem::make('Stock by Location')->url(ProductInventoryResource::getUrl()),
            NavigationItem::make('My Inventory')->url('/admin/my-inventory'),
        ])];

        $result = app(NavigationPreferenceService::class)->apply($owner, $groups);

        $this->assertSame(['My Inventory'], collect($result[0]->getItems())->map(fn ($item) => $item->getLabel())->all());
        $this->assertSame([$legacyKey], app(UserUiPreferenceService::class)->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
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

    private function foundation(EmployeeRole $role = EmployeeRole::Staff, int $held = 10): array
    {
        $f = $this->responsibilityFoundation(10);
        $f['employee']->update(['role' => $role]);
        foreach ([InventoryPermission::View->value, InventoryPermission::ViewLocationBalances->value, InventoryLocationPermission::View->value] as $permission) {
            app(EmployeePermissionOverrideService::class)->change($f['employee'], $permission, EmployeePermissionEffect::Allow, 'Owned view access test', $f['owner']);
        }
        $f['product']->update(['condition' => ProductCondition::New, 'name' => 'Full detailed laptop title including memory, processor, display and specifications']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'categoryId' => $f['product']->category_id, 'platformId' => $f['platform']->id, 'condition' => ProductCondition::New,
        ]), $f['owner']);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $allocation = app(InventoryAllocationService::class);
        $allocation->ensureShadowCoverage($f['inventory'], $f['owner']);
        $holderAccount = $allocation->employeeAccount($holder->employee->id);
        $allocation->reconcile($f['inventory'], $holderAccount, $held, $f['owner'], 'Other holder stock');

        return $f + compact('holder', 'holderAccount');
    }
}
