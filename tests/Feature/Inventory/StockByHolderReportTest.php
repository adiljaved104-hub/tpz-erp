<?php

namespace Tests\Feature\Inventory;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Inventory\StockByHolder;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Team;
use App\Models\Warehouse;
use App\Services\Inventory\StockByHolderReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockByHolderReportTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_owner_sees_employee_team_and_unassigned_balances_without_cost_fields(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $employee = $this->responsibilityUser(EmployeeRole::Staff)->employee;
        $team = Team::query()->create(['name' => 'Repair Team', 'status' => true]);
        $brand = ProductBrand::factory()->create(['name' => 'Report Brand']);
        $category = ProductCategory::factory()->create(['name' => 'Report Category']);
        $warehouse = Warehouse::factory()->create(['name' => 'Report Warehouse']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 10,
            'reserved_quantity' => 3,
            'average_cost' => '999.0000',
        ]);
        $employeeAccount = $this->account('employee:'.$employee->id, 'employee', $employee->name, false, $employee->id);
        $teamAccount = $this->account('team:'.$team->id, 'team', $team->name, false, null, $team->id);
        $systemAccount = $this->account('system', 'system', 'System / Unallocated', true);
        $employeeBalance = $this->balance($employeeAccount, $inventory, 4, 1);
        $teamBalance = $this->balance($teamAccount, $inventory, 3, 1);
        $systemBalance = $this->balance($systemAccount, $inventory, 3, 1);
        $this->assertDatabaseCount('inventory_allocation_balances', 3);
        $this->assertCount(3, app(StockByHolderReportService::class)->holderSummaries([
            'holder_id' => '', 'unassigned' => false, 'search' => '', 'brand_id' => '', 'category_id' => '', 'warehouse_id' => '',
        ]));

        $this->actingAs($owner);
        Livewire::test(StockByHolder::class)
            ->assertOk()
            ->assertSet('holderId', '')
            ->assertSee($employee->name)
            ->assertSee('Repair Team')
            ->assertSee('Unassigned / System')
            ->assertSee($product->sku)
            ->assertSee('Report Warehouse')
            ->assertSee('7')
            ->assertSee('3')
            ->assertDontSee('999.0000')
            ->assertDontSee('average_cost')
            ->call('selectHolder', (string) $employeeAccount->id)
            ->assertSee($employee->name)
            ->assertDontSeeHtml('wire:key="stock-holder-'.$teamAccount->id.'"')
            ->assertDontSeeHtml('wire:key="stock-holder-'.$systemAccount->id.'"')
            ->assertDontSeeHtml('wire:key="stock-balance-'.$teamBalance->id.'"')
            ->assertDontSeeHtml('wire:key="stock-balance-'.$systemBalance->id.'"')
            ->assertSeeHtml('wire:key="stock-balance-'.$employeeBalance->id.'"');

    }

    public function test_filters_and_unassigned_filter_use_allocation_accounts_and_keep_inactive_holders_visible(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $inactive = $this->responsibilityUser(EmployeeRole::Staff)->employee;
        $inactive->update(['status' => false]);
        $brand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Filtered Notebook']);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5]);
        $employeeAccount = $this->account('employee:'.$inactive->id, 'employee', $inactive->name, false, $inactive->id);
        $systemAccount = $this->account('system', 'system', 'System / Unallocated', true);
        $employeeBalance = $this->balance($employeeAccount, $inventory, 2, 0);
        $systemBalance = $this->balance($systemAccount, $inventory, 3, 0);

        $this->actingAs($owner);
        Livewire::test(StockByHolder::class)
            ->assertOk()
            ->assertSee($inactive->name)
            ->assertSee('(Inactive)')
            ->set('unassignedOnly', true)
            ->assertSee('Unassigned / System')
            ->assertDontSeeHtml('wire:key="stock-balance-'.$employeeBalance->id.'"')
            ->assertSeeHtml('wire:key="stock-balance-'.$systemBalance->id.'"')
            ->set('unassignedOnly', false)
            ->set('brandId', (string) $brand->id)
            ->set('categoryId', (string) $category->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('search', 'Filtered Notebook')
            ->assertSee($product->sku)
            ->set('search', 'not-a-match')
            ->assertSee('No allocation balances match these filters.');
    }

    public function test_non_owner_admin_cannot_access_stock_by_holder_directly(): void
    {
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $this->actingAs($staff);

        $this->assertFalse(StockByHolder::canAccess());
        Livewire::test(StockByHolder::class)->assertForbidden();
    }

    public function test_admin_can_access_stock_by_holder(): void
    {
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $this->actingAs($admin);

        $this->assertTrue(StockByHolder::canAccess());
        Livewire::test(StockByHolder::class)->assertOk();
    }

    private function account(string $key, string $type, string $name, bool $system, ?int $employeeId = null, ?int $teamId = null): InventoryAllocationAccount
    {
        return InventoryAllocationAccount::query()->firstOrCreate(['identity_key' => $key], [
            'type' => $type,
            'name' => $name,
            'is_system' => $system,
            'status' => true,
            'employee_id' => $employeeId,
            'team_id' => $teamId,
        ]);
    }

    private function balance(InventoryAllocationAccount $account, ProductInventory $inventory, int $allocated, int $reserved): InventoryAllocationBalance
    {
        return InventoryAllocationBalance::query()->create([
            'account_id' => $account->id,
            'product_inventory_id' => $inventory->id,
            'allocated_quantity' => $allocated,
            'reserved_quantity' => $reserved,
        ]);
    }
}
