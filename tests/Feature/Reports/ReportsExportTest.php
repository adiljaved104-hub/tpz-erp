<?php

namespace Tests\Feature\Reports;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Exports\TabularReportExport;
use App\Filament\Pages\Reports;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkSchedule;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_hub_renders_only_authorized_reports_and_direct_export_reauthorizes(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');

        $this->actingAs($owner)->get(Reports::getUrl())->assertOk()->assertSee('Report Selection')->assertSee('Current Inventory');
        Livewire::actingAs($staff)->test(Reports::class)->assertSee('Orders Report')->assertDontSee('Current Inventory');
        $this->actingAs($staff)->get(route('reports.export', ['report' => 'orders', 'format' => 'csv']))->assertForbidden();
    }

    public function test_report_selector_and_url_state_use_canonical_keys(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');

        Livewire::actingAs($owner)->test(Reports::class)
            ->assertSeeHtml('value="attendance"')
            ->set('report', 'attendance')
            ->assertSet('report', 'attendance');

        Livewire::actingAs($owner)->withQueryParams(['report' => '0'])->test(Reports::class)
            ->assertSet('report', 'orders');
    }

    public function test_owner_can_download_attendance_inventory_and_orders_xlsx_through_authenticated_endpoint(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');

        foreach (['attendance', 'current_inventory', 'orders'] as $report) {
            $this->actingAs($owner)
                ->get(route('reports.export', ['report' => $report, 'format' => 'xlsx']))
                ->assertOk()
                ->assertDownload(str($report)->slug('-').'-'.now()->format('Y-m-d').'.xlsx');
        }

        $this->assertDatabaseCount('activity_logs', 3);
    }

    public function test_csv_and_pdf_downloads_use_the_same_canonical_endpoint(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');

        foreach (['csv', 'pdf'] as $format) {
            $this->actingAs($owner)
                ->get(route('reports.export', ['report' => 'attendance', 'format' => $format]))
                ->assertOk()
                ->assertDownload('attendance-'.now()->format('Y-m-d').'.'.$format);
        }

        Livewire::actingAs($owner)->test(Reports::class)
            ->assertSeeHtml('/admin/reports/download/orders/xlsx')
            ->assertDontSeeHtml('wire:click="export');
    }

    public function test_invalid_report_identity_is_rejected_without_success_audit(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');

        $this->actingAs($owner)
            ->get(route('reports.export', ['report' => 'not-a-report', 'format' => 'xlsx']))
            ->assertForbidden();
        $this->actingAs($owner)
            ->get(route('reports.export', ['report' => '0', 'format' => 'xlsx']))
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_logs', ['event' => 'report.exported']);
    }

    public function test_inventory_financial_projection_is_added_only_when_authorized(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'available_quantity' => 5, 'reserved_quantity' => 2, 'average_cost' => '1200.0000']);

        $ownerReport = app(ReportQueryService::class)->run($owner, 'current_inventory', []);
        DB::enableQueryLog();
        $adminReport = app(ReportQueryService::class)->run($admin, 'current_inventory', []);
        $restrictedSql = collect(DB::getQueryLog())->pluck('query')->implode(' ');

        $this->assertContains('average_cost', array_column($ownerReport->columns, 'key'));
        $this->assertContains('inventory_value', array_column($ownerReport->columns, 'key'));
        $this->assertNotContains('average_cost', array_column($adminReport->columns, 'key'));
        $this->assertNotContains('inventory_value', array_column($adminReport->columns, 'key'));
        $this->assertStringNotContainsString('average_cost', strtolower($restrictedSql));
        $this->assertSame(3, (int) $adminReport->rows->first()['sellable']);
    }

    public function test_staff_inventory_query_is_limited_to_responsibility_products(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        EmployeePermissionOverride::query()->create(['employee_id' => $staff->employee->id, 'permission_key' => InventoryPermission::View->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Report scope test']);
        $warehouse = Warehouse::factory()->create();
        $allowed = Product::factory()->create(['sku' => 'ALLOWED']);
        $other = Product::factory()->create(['sku' => 'OTHER']);
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $allowed->id, 'available_quantity' => 2]);
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $other->id, 'available_quantity' => 9]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id, 'assigned_by_user_id' => $owner->id]);
        DB::table('responsibility_assignment_products')->insert(['assignment_id' => $assignment->id, 'product_id' => $allowed->id]);

        $report = app(ReportQueryService::class)->run($staff, 'current_inventory', []);

        $this->assertSame(['ALLOWED'], $report->rows->pluck('sku')->all());
    }

    public function test_restricted_financial_columns_are_absent_from_download_projections(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => InventoryPermission::Export->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Export projection test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);
        ProductInventory::factory()->create([
            'warehouse_id' => Warehouse::factory()->create()->id,
            'available_quantity' => 2,
            'average_cost' => '9876.5432',
        ]);

        $exports = app(ReportExportService::class);
        $csv = $this->stream($exports->export($admin, 'current_inventory', 'csv', []));
        $this->assertStringNotContainsString('Average Cost', $csv);
        $this->assertStringNotContainsString('Inventory Value', $csv);
        $this->assertStringNotContainsString('9876.5432', $csv);

        $xlsx = $exports->export($admin, 'current_inventory', 'xlsx', []);
        $headings = IOFactory::load($xlsx->getFile()->getPathname())->getActiveSheet()->rangeToArray('A5:Z5')[0];
        $this->assertNotContains('Average Cost', $headings);
        $this->assertNotContains('Inventory Value', $headings);

        $this->assertStringStartsWith('%PDF', (string) $exports->export($admin, 'current_inventory', 'pdf', [])->getContent());
    }

    public function test_staff_inventory_export_uses_scoped_non_financial_projection(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        foreach ([InventoryPermission::View, InventoryPermission::Export] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $staff->employee->id,
                'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow,
                'granted_by_user_id' => $owner->id,
                'reason' => 'Scoped export test',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $product = Product::factory()->create();
        $inventory = ProductInventory::factory()->create([
            'warehouse_id' => Warehouse::factory()->create()->id,
            'product_id' => $product->id,
            'average_cost' => '4321.0000',
        ]);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->employee->id,
            'assigned_by_user_id' => $owner->id,
        ]);
        DB::table('responsibility_assignment_products')->insert(['assignment_id' => $assignment->id, 'product_id' => $product->id]);

        $csv = $this->stream(app(ReportExportService::class)->export($staff, 'current_inventory', 'csv', []));

        $this->assertStringContainsString($inventory->product->sku, $csv);
        $this->assertStringNotContainsString('Average Cost', $csv);
        $this->assertStringNotContainsString('4321', $csv);
    }

    public function test_csv_xlsx_and_pdf_exports_use_authorized_projection_and_are_audited(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => Product::factory()->create(['name' => 'Laptop, "Special"']), 'available_quantity' => 1, 'average_cost' => '100.0000']);
        $exports = app(ReportExportService::class);

        $csv = $this->stream($exports->export($owner, 'current_inventory', 'csv', []));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Laptop, ""Special"""', $csv);

        $xlsx = $exports->export($owner, 'current_inventory', 'xlsx', []);
        $this->assertSame('PK', file_get_contents($xlsx->getFile()->getPathname(), false, null, 0, 2));
        $report = app(ReportQueryService::class)->run($owner, 'current_inventory', []);
        $rows = (new TabularReportExport($report))->array();
        $moneyColumn = array_search('Average Cost', $rows[4], true);
        $this->assertIsFloat($rows[5][$moneyColumn]);

        $pdf = (string) $exports->export($owner, 'current_inventory', 'pdf', [])->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertDatabaseCount('activity_logs', 3);
        $this->assertDatabaseHas('activity_logs', ['event' => 'report.exported', 'actor_user_id' => $owner->id]);
    }

    public function test_pdf_accepts_valid_unicode_and_scrubs_invalid_utf8_without_mutating_source(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $name = "اردو Laptop \xC3\x28";
        $product = Product::factory()->create(['name' => $name]);
        ProductInventory::factory()->create([
            'warehouse_id' => Warehouse::factory()->create()->id,
            'product_id' => $product->id,
        ]);

        $pdf = app(ReportExportService::class)->export($owner, 'current_inventory', 'pdf', []);

        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertSame($name, $product->fresh()->name);
    }

    public function test_attendance_projection_localizes_utc_timestamps_and_preserves_raw_values(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $schedule = $this->schedule('Asia/Karachi');
        $attendance = EmployeeAttendance::query()->create([
            'employee_id' => $owner->employee->id,
            'attendance_date' => '2026-08-21',
            'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'first_check_in_at' => '2026-08-21 03:41:12',
            'last_check_out_at' => '2026-08-21 12:48:00',
            'status' => AttendanceStatus::Late,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'source' => 'system_derived',
            'is_overridden' => false,
            'calculated_at' => now(),
        ]);

        $report = app(ReportQueryService::class)->run($owner, 'attendance', [
            'from' => '2026-08-21',
            'to' => '2026-08-22',
        ]);
        $row = $report->rows->sole();

        $this->assertSame('21 Aug 2026', $row['attendance_date']);
        $this->assertSame('08:41 AM', $row['first_check_in_at']);
        $this->assertSame('05:48 PM', $row['last_check_out_at']);
        $this->assertArrayNotHasKey('attendance_timezone', $row);
        $this->assertSame('2026-08-21 03:41:12', $attendance->fresh()->getRawOriginal('first_check_in_at'));
        $this->assertSame('2026-08-21 12:48:00', $attendance->fresh()->getRawOriginal('last_check_out_at'));
    }

    public function test_attendance_missing_checkout_and_near_midnight_date_are_presented_safely(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $schedule = $this->schedule('Asia/Karachi');
        EmployeeAttendance::query()->create([
            'employee_id' => $owner->employee->id,
            'attendance_date' => '2026-08-21',
            'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'first_check_in_at' => '2026-08-20 19:15:00',
            'last_check_out_at' => null,
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'source' => 'system_derived',
            'is_overridden' => false,
            'calculated_at' => now(),
        ]);

        $row = app(ReportQueryService::class)->run($owner, 'attendance', [
            'from' => '2026-08-21',
            'to' => '2026-08-22',
        ])->rows->sole();

        $this->assertSame('21 Aug 2026', $row['attendance_date']);
        $this->assertSame('12:15 AM', $row['first_check_in_at']);
        $this->assertSame('—', $row['last_check_out_at']);
    }

    public function test_attendance_csv_xlsx_pdf_and_preview_share_localized_projection(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $schedule = $this->schedule('Asia/Karachi');
        EmployeeAttendance::query()->create([
            'employee_id' => $owner->employee->id,
            'attendance_date' => '2026-08-21',
            'work_schedule_id' => $schedule->id,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'first_check_in_at' => '2026-08-21 03:41:12',
            'last_check_out_at' => '2026-08-21 12:48:00',
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'source' => 'system_derived',
            'is_overridden' => false,
            'calculated_at' => now(),
        ]);
        $filters = ['from' => '2026-08-20', 'to' => '2026-08-22'];
        $exports = app(ReportExportService::class);

        $csv = $this->stream($exports->export($owner, 'attendance', 'csv', $filters));
        $this->assertStringContainsString('21 Aug 2026', $csv);
        $this->assertStringContainsString('08:41 AM', $csv);
        $this->assertStringContainsString('05:48 PM', $csv);

        $xlsx = $exports->export($owner, 'attendance', 'xlsx', $filters);
        $sheet = IOFactory::load($xlsx->getFile()->getPathname())->getActiveSheet();
        $values = $sheet->toArray();
        $this->assertTrue(collect($values)->flatten()->contains('21 Aug 2026'));
        $this->assertTrue(collect($values)->flatten()->contains('08:41 AM'));
        $this->assertTrue(collect($values)->flatten()->contains('05:48 PM'));

        $this->assertStringStartsWith('%PDF', (string) $exports->export($owner, 'attendance', 'pdf', $filters)->getContent());
        Livewire::actingAs($owner)->withQueryParams(['report' => 'attendance', ...$filters])->test(Reports::class)
            ->assertSee('21 Aug 2026')->assertSee('08:41 AM')->assertSee('05:48 PM')->assertDontSee('03:41:12');
    }

    public function test_export_limit_and_catalog_are_centralized(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $catalog = app(ReportCatalog::class)->available($owner);

        $this->assertCount(46, $catalog);
        $this->assertSame(10000, ReportQueryService::EXPORT_LIMIT);
        $this->assertTrue(app(ReportCatalog::class)->canExport($owner, 'current_inventory'));
    }

    private function stream(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function user(EmployeeRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create(['name' => $name, 'email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function schedule(string $timezone): WorkSchedule
    {
        return WorkSchedule::query()->create([
            'code' => 'REPORT_TIMEZONE',
            'name' => 'Report Timezone',
            'timezone' => $timezone,
            'schedule_type' => 'standard',
            'cycle_length_weeks' => 1,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'status' => true,
        ]);
    }
}
