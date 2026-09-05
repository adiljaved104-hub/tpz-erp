<?php

namespace Tests\Feature\Reports;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseCostCenter;
use App\Enums\ExpensePermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Filament\Pages\Reports;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\WebSalesService;
use App\Services\Reports\Providers\CoreReportProvider;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportQueryService;
use App\Services\Reports\ReportRegistry;
use App\Support\ReportValueFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ReportRegistryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_preserves_core_reports_and_discovers_module_reports_with_unique_keys(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $reports = app(ReportCatalog::class)->available($owner);

        $this->assertCount(46, $reports);
        $this->assertCount(46, array_unique(array_keys($reports)));
        $this->assertSame('Sales & Orders', $reports['orders']['group']);
        $this->assertSame('Web Sales', $reports['web_sales.orders']['group']);
        $this->assertSame('Finance', $reports['finance.expenses']['group']);
        $this->assertSame('Finance', $reports['office_finance.cashbook']['group']);
        $this->assertSame('Sales', $reports['sales.quotations']['group']);
        $this->assertSame('Business Expenses (AED)', $reports['finance.expenses']['title']);
        $this->assertSame('Pakistan Office Expenses (PKR)', $reports['office_finance.expenses']['title']);
        $this->assertNotEmpty($reports['finance.expenses']['pdf_columns']);
        $this->assertNotEmpty($reports['office_finance.expenses']['pdf_columns']);
        $this->assertSame(['xlsx', 'csv', 'pdf'], $reports['web_sales.profitability']['formats']);

        Livewire::actingAs($owner)->test(Reports::class)
            ->assertSee('Web Sales Profitability')
            ->assertSee('Expenses by Cost Center')
            ->set('report', 'finance.expenses')
            ->assertSet('report', 'finance.expenses')
            ->set('costCenter', ExpenseCostCenter::WebSales->value)
            ->assertSet('costCenter', ExpenseCostCenter::WebSales->value);
    }

    public function test_selected_report_filter_options_are_narrow_and_summary_currency_is_explicit(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $options = app(ReportQueryService::class)->filterOptions($owner, ['status', 'category', 'cost_center', 'employee']);

        $this->assertSame([], $options['products']);
        $this->assertSame([], $options['platforms']);
        $this->assertSame([], $options['warehouses']);
        $this->assertNotEmpty($options['expenseCategories']);
        $this->assertNotEmpty($options['expenseCostCenters']);

        $expense = app(ReportQueryService::class)->run($owner, 'finance.expenses', []);
        $office = app(ReportQueryService::class)->run($owner, 'office_finance.expenses', []);
        $this->assertSame('AED 0.00', ReportValueFormatter::summary($expense, 'Total Expenses', 0.0));
        $this->assertSame('PKR 0.00', ReportValueFormatter::summary($office, 'Expenses', 0.0));
    }

    public function test_registry_rejects_duplicate_provider_keys(): void
    {
        $provider = app(CoreReportProvider::class);

        $this->expectException(LogicException::class);
        (new ReportRegistry([$provider, $provider]))->all();
    }

    public function test_expense_filters_and_sql_level_amount_omission_match_source_permissions(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        $this->allow($admin, $owner, ExpensePermission::View->value);
        Expense::query()->create([
            'expense_date' => '2026-08-15', 'category' => ExpenseCategory::Advertising,
            'description' => 'Web campaign', 'amount' => '125.50', 'cost_center' => ExpenseCostCenter::WebSales,
            'created_by_user_id' => $owner->id,
        ]);
        Expense::query()->create([
            'expense_date' => '2026-07-15', 'category' => ExpenseCategory::OfficeRent,
            'description' => 'Office', 'amount' => '900.00', 'cost_center' => ExpenseCostCenter::General,
            'created_by_user_id' => $owner->id,
        ]);
        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'cost_center' => ExpenseCostCenter::WebSales->value];

        $ownerReport = app(ReportQueryService::class)->run($owner, 'finance.expenses', $filters);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $adminReport = app(ReportQueryService::class)->run($admin, 'finance.expenses', $filters);
        $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' '));

        $this->assertSame(1, $ownerReport->totalRows);
        $this->assertSame(125.5, $ownerReport->summary['Total Expenses']);
        $this->assertContains('amount', array_column($ownerReport->columns, 'key'));
        $this->assertNotContains('amount', array_column($adminReport->columns, 'key'));
        $this->assertStringNotContainsString('ex.amount', $sql);
        $this->assertStringNotContainsString('125.50', $this->stream(app(ReportExportService::class)->export($admin, 'finance.expenses', 'csv', $filters)));
        $this->actingAs($admin)->get(route('reports.export', ['report' => 'web_sales.profitability', 'format' => 'pdf']))->assertForbidden();
    }

    public function test_owner_web_sales_profitability_reuses_snapshots_and_expenses_and_exports_all_formats(): void
    {
        [$owner, $product] = $this->webSalesFoundation();
        $service = app(WebSalesService::class);
        $order = $service->createConfirmed(new WebSalesOrderData(
            'Report Customer', '+971 50 100 2000', WebSalesChannel::Website, WebSalesDeliveryType::Courier,
            'Courier', 'TRACK-1', [new OrderItemData($product->id, 1, '1500.00')], (string) Str::uuid(),
        ), $owner);
        $service->ship($order, (string) Str::uuid(), $owner);
        Expense::query()->create([
            'expense_date' => today()->toDateString(), 'category' => ExpenseCategory::Advertising,
            'description' => 'Web campaign', 'amount' => '100.00', 'cost_center' => ExpenseCostCenter::WebSales,
            'created_by_user_id' => $owner->id,
        ]);

        $report = app(ReportQueryService::class)->run($owner, 'web_sales.profitability', []);

        $this->assertSame(1500.0, $report->summary['Revenue']);
        $this->assertSame(500.0, $report->summary['COGS']);
        $this->assertSame(1000.0, $report->summary['Gross Profit']);
        $this->assertSame(100.0, $report->summary['Operating Expenses']);
        $this->assertSame(900.0, $report->summary['Net Profit']);
        $exports = app(ReportExportService::class);
        $this->assertStringContainsString('Advertising', $this->stream($exports->export($owner, 'web_sales.profitability', 'csv', [])));
        $xlsx = $exports->export($owner, 'web_sales.profitability', 'xlsx', []);
        $this->assertNotEmpty(IOFactory::load($xlsx->getFile()->getPathname())->getActiveSheet()->toArray());
        $this->assertStringStartsWith('%PDF', (string) $exports->export($owner, 'web_sales.profitability', 'pdf', [])->getContent());
    }

    private function allow(User $employeeUser, User $owner, string $permission): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $employeeUser->employee->id, 'permission_key' => $permission,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
            'reason' => 'Report registry test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($employeeUser->employee->id);
    }

    /** @return array{User, Product} */
    private function webSalesFoundation(): array
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create(['selling_price' => '1500.00']);
        ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 5, 'reserved_quantity' => 0, 'average_cost' => '500.0000',
        ]);

        return [$owner, $product];
    }

    private function user(EmployeeRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create(['name' => $name, 'email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function stream(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
