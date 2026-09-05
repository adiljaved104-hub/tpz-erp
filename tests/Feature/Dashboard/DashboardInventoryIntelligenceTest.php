<?php

namespace Tests\Feature\Dashboard;

use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Filament\Widgets\ErpDashboardOverview;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\PendingPurchaseReceivingStats;
use App\Filament\Widgets\ResponsibilityCapacityWarnings;
use App\Filament\Widgets\TaskSupervisionOverview;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderFulfillmentItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Dashboard\DashboardInventoryIntelligenceService;
use App\Services\Dashboard\ErpDashboardService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardInventoryIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_period_accepts_valid_dates_rejects_invalid_ranges_and_keeps_current_inventory(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        ProductInventory::factory()->create(['available_quantity' => 7, 'reserved_quantity' => 2]);

        Livewire::actingAs($owner)->test(ErpDashboardOverview::class)
            ->call('setPeriod', 'custom')
            ->assertSet('period', 'custom')
            ->assertSee('From Date')
            ->assertSee('To Date')
            ->set('customFrom', '2026-08-01')
            ->set('customTo', '2026-08-20')
            ->call('applyCustomRange')
            ->assertHasNoErrors()
            ->assertSet('appliedCustomFrom', '2026-08-01')
            ->assertSet('appliedCustomTo', '2026-08-20')
            ->assertSee('Sellable Inventory Units')
            ->assertSee('5')
            ->set('customFrom', '2026-08-20')
            ->set('customTo', '2026-08-01')
            ->call('applyCustomRange')
            ->assertHasErrors(['customTo']);
    }

    public function test_sellable_low_stock_and_out_of_stock_use_only_available_minus_reserved(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 4,
            'reserved_quantity' => 2,
            'damaged_quantity' => 10,
            'marketplace_non_sellable_quantity' => 8,
            'qc_pending_quantity' => 6,
        ]);
        ProductInventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 2,
            'reserved_quantity' => 2,
            'damaged_quantity' => 5,
        ]);
        ProductInventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        $data = $this->intelligence($owner, '2026-08-01', '2026-08-31');

        $this->assertSame(7, $data['sellable_units']);
        $this->assertSame(1, $data['low_stock_count']);
        $this->assertSame(1, $data['out_of_stock_count']);
        $this->assertSame(2, $data['threshold']);
    }

    public function test_top_sellers_use_fulfilled_quantities_and_custom_period_only(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $warehouse = Warehouse::factory()->create();
        $fastLow = Product::factory()->create(['name' => 'Fast Low']);
        $wellStocked = Product::factory()->create(['name' => 'Well Stocked']);
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $fastLow->id, 'available_quantity' => 2]);
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $wellStocked->id, 'available_quantity' => 20]);

        $this->fulfilled($owner, $warehouse, $fastLow, 7, '2026-08-10');
        $this->fulfilled($owner, $warehouse, $wellStocked, 9, '2026-08-12');
        $this->fulfilled($owner, $warehouse, $fastLow, 50, '2026-07-15');
        $this->unfulfilled($owner, $warehouse, Product::factory()->create(), 99, '2026-08-11');
        $this->fulfilled($owner, $warehouse, Product::factory()->create(), 80, '2026-08-13', OrderStatus::Cancelled);
        $this->unfulfilled($owner, $warehouse, Product::factory()->create(), 1, '2026-08-31');

        $data = $this->intelligence($owner, '2026-08-01', '2026-08-31');
        $dashboard = app(ErpDashboardService::class)->forUser($owner, 'custom', '2026-08-01', '2026-08-31');

        $this->assertSame([$wellStocked->id, $fastLow->id], $data['top_sellers']->pluck('product_id')->all());
        $this->assertSame([9, 7], $data['top_sellers']->pluck('sold_quantity')->all());
        $this->assertSame([$fastLow->id], $data['fast_selling_low_stock']->pluck('product_id')->all());
        $this->assertSame(5, $dashboard['cards']->firstWhere('key', 'orders')['value']);
    }

    public function test_staff_and_owner_employee_scope_use_existing_responsibility_assignments(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        $team = Team::query()->create(['name' => 'Sales Team', 'status' => true]);
        $staff->employee->update(['team_id' => $team->id]);
        $warehouse = Warehouse::factory()->create();
        $relevant = Product::factory()->create();
        $unrelated = Product::factory()->create();
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $relevant->id, 'available_quantity' => 2]);
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $unrelated->id, 'available_quantity' => 20]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id, 'assigned_by_user_id' => $owner->id]);
        DB::table('responsibility_assignment_products')->insert(['assignment_id' => $assignment->id, 'product_id' => $relevant->id]);

        $staffData = $this->intelligence($staff, '2026-08-01', '2026-08-31');
        $ownerEmployeeData = app(DashboardInventoryIntelligenceService::class)->forUser(
            $owner,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
            'employee',
            null,
            $staff->employee->id,
        );
        $ownerTeamData = app(DashboardInventoryIntelligenceService::class)->forUser(
            $owner,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
            'team',
            $team->id,
        );

        $this->assertSame(2, $staffData['sellable_units']);
        $this->assertSame(2, $ownerEmployeeData['sellable_units']);
        $this->assertSame(2, $ownerTeamData['sellable_units']);
        $this->assertSame('Staff', $ownerEmployeeData['scope_label']);
        $this->assertCount(1, $staffData['attention']);
        $this->assertSame($relevant->id, $staffData['attention']->first()['product_id']);
    }

    public function test_main_panel_registers_only_the_consolidated_dashboard_widget(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertContains(ErpDashboardOverview::class, $widgets);
        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(MyTasksWidget::class, $widgets);
        $this->assertNotContains(TaskSupervisionOverview::class, $widgets);
        $this->assertNotContains(PendingPurchaseReceivingStats::class, $widgets);
        $this->assertNotContains(ResponsibilityCapacityWarnings::class, $widgets);
    }

    public function test_manager_inventory_selector_is_limited_to_their_existing_team_scope(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $manager = $this->user(EmployeeRole::Manager, 'Manager');
        $ownStaff = $this->user(EmployeeRole::Staff, 'Own Staff');
        $otherStaff = $this->user(EmployeeRole::Staff, 'Other Staff');
        $ownTeam = Team::query()->create(['name' => 'Own Team', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other Team', 'status' => true]);
        $manager->employee->update(['team_id' => $ownTeam->id]);
        $ownStaff->employee->update(['team_id' => $ownTeam->id]);
        $otherStaff->employee->update(['team_id' => $otherTeam->id]);
        $warehouse = Warehouse::factory()->create();
        $ownProduct = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $ownProduct->id, 'available_quantity' => 2]);
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $otherProduct->id, 'available_quantity' => 9]);
        foreach ([[$ownStaff, $ownProduct], [$otherStaff, $otherProduct]] as [$employee, $product]) {
            $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $employee->employee->id, 'assigned_by_user_id' => $owner->id]);
            DB::table('responsibility_assignment_products')->insert(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
        }

        $allowed = app(DashboardInventoryIntelligenceService::class)->forUser($manager, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), 'team', $ownTeam->id);
        $denied = app(DashboardInventoryIntelligenceService::class)->forUser($manager, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), 'team', $otherTeam->id);

        $this->assertSame(2, $allowed['sellable_units']);
        $this->assertSame(0, $denied['sellable_units']);
        $this->assertSame([$ownTeam->id => $ownTeam->name], $allowed['team_options']);
        $this->assertArrayNotHasKey($otherStaff->employee->id, $allowed['employee_options']);
    }

    /** @return array<string, mixed> */
    private function intelligence(User $user, string $from, string $to): array
    {
        return app(DashboardInventoryIntelligenceService::class)->forUser(
            $user,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    private function fulfilled(User $actor, Warehouse $warehouse, Product $product, int $quantity, string $date, OrderStatus $status = OrderStatus::Fulfilled): void
    {
        $orderItem = $this->unfulfilled($actor, $warehouse, $product, $quantity, $date, $status);
        $fulfillment = OrderFulfillment::query()->create([
            'reference' => 'SOF-'.Str::uuid(),
            'order_id' => $orderItem->order_id,
            'movement_group' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'fulfilled_by_user_id' => $actor->id,
            'fulfilled_at' => $date.' 12:00:00',
        ]);
        $inventory = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first()
            ?? ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id]);
        OrderFulfillmentItem::query()->create([
            'order_fulfillment_id' => $fulfillment->id,
            'order_item_id' => $orderItem->id,
            'product_inventory_id' => $inventory->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'inventory_unit_cost' => '1.0000',
            'cogs_total' => (string) $quantity,
            'posting_key' => (string) Str::uuid(),
        ]);
    }

    private function unfulfilled(User $actor, Warehouse $warehouse, Product $product, int $quantity, string $date, OrderStatus $status = OrderStatus::Confirmed): OrderItem
    {
        $order = Order::query()->create([
            'reference' => 'SO-'.Str::uuid(),
            'source' => 'manual',
            'status' => $status,
            'warehouse_id' => $warehouse->id,
            'order_date' => $date,
            'grand_total' => $quantity * 100,
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $actor->id,
        ]);

        return OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'ordered_quantity' => $quantity,
            'selling_price' => '100.00',
            'line_total' => $quantity * 100,
        ]);
    }

    private function user(EmployeeRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create(['name' => $name, 'email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
