<?php

namespace Tests\Feature\DemoData;

use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\Enums\EmployeeRole;
use App\Enums\ProductMatchClassification;
use App\Enums\ProductMatchContext;
use App\Models\CompanyProfile;
use App\Models\Complaint;
use App\Models\Component;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderUpgradeExecution;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\SafetClaim;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Services\DemoData\StagingDemoDataService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\ProductIntelligence\ProductMatchService;
use App\Services\Reports\ReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StagingDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'demo.enabled' => true,
            'demo.allowed_database' => ':memory:',
            'demo.password' => 'Test-only strong demo password 2026!',
            'mail.notifications_enabled' => false,
        ]);
        Mail::fake();
        $this->owner = User::factory()->create(['name' => 'Protected Owner', 'email' => 'owner@example.com']);
        Employee::factory()->create([
            'user_id' => $this->owner->id, 'employee_id' => 'TPZ-0001', 'name' => 'Protected Owner',
            'email' => $this->owner->email, 'role' => EmployeeRole::Owner, 'status' => true,
        ]);
        CompanyProfile::query()->create([
            'id' => 1, 'company_name_en' => 'Tech Point Zone Demo Test', 'trn' => '100000000000001',
            'address_en' => 'Dubai, UAE', 'phone' => '+971000000000', 'email' => 'office@techpointzone.com',
            'country' => 'United Arab Emirates', 'emirate' => 'Dubai', 'updated_by_user_id' => $this->owner->id,
        ]);
    }

    public function test_full_profile_is_idempotent_and_preserves_owner_and_non_demo_data(): void
    {
        $nonDemo = Product::factory()->create(['name' => 'Existing protected Product', 'sku' => 'EXISTING-001']);
        $ownerBefore = $this->owner->fresh()->only(['id', 'name', 'email', 'password']);
        $nonDemoBefore = $nonDemo->fresh()->getAttributes();

        $first = app(StagingDemoDataService::class)->run('full', false, 'STAGING-DEMO');
        $counts = $this->businessCounts();
        $second = app(StagingDemoDataService::class)->run('full', false, 'STAGING-DEMO');

        $this->assertFalse($first->dryRun);
        $this->assertSame($counts, $this->businessCounts());
        $this->assertSame($first->counts, $second->counts);
        $this->assertSame($ownerBefore, $this->owner->fresh()->only(['id', 'name', 'email', 'password']));
        $this->assertSame($nonDemoBefore, $nonDemo->fresh()->getAttributes());
        $this->assertSame(6, User::query()->where('email', 'like', 'demo.%@techpointzone.com')->count());
        $this->assertSame(3, Team::query()->where('description', 'like', '[DEMO:staging-v1]%')->count());
        $this->assertSame(32, Product::query()->products()->where('description', 'like', '[DEMO:staging-v1]%')->count());
        $this->assertSame(8, Component::query()->where('specification', 'like', '[DEMO:staging-v1]%')->count());
        $this->assertSame(60, Order::query()->where('notes', 'like', '%[DEMO:staging-v1]%')->count());
        $this->assertSame(8, Quotation::query()->where('external_reference', 'like', 'DEMO-QUO-v1-%')->count());
        $this->assertSame(24, Expense::query()->where('reference_note', 'like', '[DEMO:staging-v1]%')->count());
        Mail::assertNothingSent();
    }

    public function test_inventory_reservations_costs_fulfilment_and_cancellation_reconcile(): void
    {
        app(StagingDemoDataService::class)->run('full', false, 'STAGING-DEMO');

        $this->assertTrue(ProductInventory::query()->where('average_cost', '>', 0)->exists());
        $this->assertTrue(InventoryReservation::query()->where('status', 'active')->exists());
        $this->assertTrue(OrderUpgradeExecution::query()->where('final_configured_cogs', '>', 0)->exists());
        $this->assertTrue(Order::query()->where('status', 'cancelled')->exists());
        $this->assertTrue(StockMovement::query()->where('movement_type', 'reservation_release')->exists());

        $this->assertCount(0, app(InventoryReconciliationService::class)->discrepancies());
        foreach (ProductInventory::query()->get() as $inventory) {
            $this->assertGreaterThanOrEqual(0, $inventory->available_quantity);
            $this->assertGreaterThanOrEqual(0, $inventory->reserved_quantity);
        }
    }

    public function test_demo_dates_cover_today_week_month_previous_month_and_history(): void
    {
        app(StagingDemoDataService::class)->run('full', false, 'STAGING-DEMO');

        $dates = Order::query()->where('notes', 'like', '%[DEMO:staging-v1]%')->pluck('order_date');
        $this->assertTrue($dates->contains(fn ($date) => $date->isToday()));
        $this->assertTrue($dates->contains(fn ($date) => $date->betweenIncluded(today()->startOfWeek(), today()->endOfWeek())));
        $this->assertTrue($dates->contains(fn ($date) => $date->isSameMonth(today())));
        $this->assertTrue($dates->contains(fn ($date) => $date->isSameMonth(today()->subMonth())));
        $this->assertTrue($dates->contains(fn ($date) => $date->lt(today()->subDays(60))));
    }

    public function test_after_sales_operations_reports_and_product_intelligence_scenarios_are_usable(): void
    {
        app(StagingDemoDataService::class)->run('full', false, 'STAGING-DEMO');

        $this->assertSame(10, CustomerReturn::query()->where('notes', 'like', '[DEMO:staging-v1]%')->count());
        $this->assertSame(4, SafetClaim::query()->whereHas('customerReturn', fn ($query) => $query->where('notes', 'like', '[DEMO:staging-v1]%'))->count());
        $this->assertSame(4, WarrantyRepair::query()->where('notes', 'like', '[DEMO:staging-v1]%')->count());
        $this->assertSame(4, Complaint::query()->where('description', 'like', '[DEMO:staging-v1]%')->count());
        $this->assertSame(16, Task::query()->where('title', 'like', '[DEMO:staging-v1]%')->count());
        $this->assertTrue(Task::query()->where('status', 'completed')->exists());
        $this->assertTrue(Task::query()->whereHas('completionSubmissions', fn ($query) => $query->where('status', 'pending'))->exists());

        $report = app(ReportQueryService::class)->run($this->owner, 'orders', [
            'from' => today()->subDays(90)->toDateString(), 'to' => today()->toDateString(),
        ]);
        $this->assertGreaterThanOrEqual(40, $report->totalRows);

        $stockBefore = ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'average_cost'])->all();
        DB::enableQueryLog();
        $matches = app(ProductMatchService::class)->match(new ProductMatchRequest(
            'HP DemoBook 1 16GB 512GB', ProductMatchContext::Order, $this->owner,
            Warehouse::query()->where('code', 'MAIN')->value('id'),
        ));
        $sql = mb_strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
        $this->assertTrue($matches->contains(fn ($match) => $match->classification === ProductMatchClassification::BuildableConfiguration));
        foreach (['cost_price', 'average_cost', 'approved_oem_recovery_value', 'recovery_value_override', 'labour_unit_cost', 'suggested_selling_addon', 'default_selling_price'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
        $this->assertSame($stockBefore, ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'average_cost'])->all());
    }

    public function test_changed_demo_marker_fingerprint_is_rejected_instead_of_overwritten(): void
    {
        app(StagingDemoDataService::class)->run('full', false, 'STAGING-DEMO');
        Product::query()->where('model', 'DEMO-LT-001')->update(['description' => '[DEMO:staging-v1] tampered fixture']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected fingerprint');
        app(StagingDemoDataService::class)->run('full', false, 'STAGING-DEMO');
    }

    public function test_dry_run_reports_plan_without_creating_records(): void
    {
        $report = app(StagingDemoDataService::class)->run('full', true, null);
        $this->assertTrue($report->dryRun);
        $this->assertSame(60, $report->counts['orders']);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    /** @return array<string, int> */
    private function businessCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'products' => Product::query()->count(),
            'purchases' => Purchase::query()->count(),
            'orders' => Order::query()->count(),
            'reservations' => InventoryReservation::query()->count(),
            'movements' => StockMovement::query()->count(),
            'quotations' => Quotation::query()->count(),
            'tasks' => Task::query()->count(),
            'expenses' => Expense::query()->count(),
        ];
    }
}
