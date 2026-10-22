<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\HistoricalLeaveRecords;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Filament\Pages\Hr\LeavePolicySettings;
use App\Filament\Pages\Hr\VerifyEmployeeJoiningDate;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Hr\HistoricalLeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HrLeaveInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_policy_uses_database_values_and_future_rules_do_not_appear_current(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $current = LeavePolicy::query()->where('status', true)->firstOrFail();
        $current->forceFill([
            'annual_leave_entitlement_days' => '14.00', 'leave_year_basis' => 'calendar_year',
            'monthly_accrual_enabled' => true, 'carry_forward_enabled' => true,
            'maximum_carry_forward_days' => '3.00', 'effective_to' => now()->addMonth()->subDay()->toDateString(),
        ])->save();
        $future = $current->replicate();
        $future->forceFill(['annual_leave_entitlement_days' => '20.00', 'effective_from' => now()->addMonth()->toDateString(), 'effective_to' => null])->save();
        $before = LeavePolicy::query()->get()->toArray();

        Livewire::actingAs($owner)->test(LeavePolicySettings::class)
            ->assertSet('annualEntitlementDays', '14.00')
            ->assertSee('14.00 days per leave year')
            ->assertSee('Calendar Year')
            ->assertSee('Yes — up to 3.00 days')
            ->assertSee('Waiting for HR verification and final activation')
            ->assertSee('Based on Joining Date, once enabled')
            ->assertSee('Scheduled')
            ->assertDontSee('HR setup approved');

        $this->assertSame($before, LeavePolicy::query()->get()->toArray());
        $this->assertDatabaseCount('historical_leave_workflow_activations', 0);
    }

    public function test_approved_setup_and_applicable_anniversary_policy_are_displayed_separately(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        LeavePolicy::query()->where('status', true)->update(['leave_year_basis' => 'joining_anniversary']);
        DB::table('historical_leave_workflow_activations')->insert([
            'workflow' => 'annual_leave', 'approved_by_user_id' => $owner->id, 'reviewed_employee_count' => 1,
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::actingAs($owner)->test(LeavePolicySettings::class)
            ->assertSee('HR setup approved')
            ->assertSee('Based on Joining Date')
            ->assertSee('12.00 days per leave year')
            ->assertDontSee('Waiting for HR verification and final activation');
    }

    public function test_joining_date_picker_includes_missing_dates_and_opens_the_existing_verification_page(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $missing = $this->user(EmployeeRole::Staff, 'Employee Needs Date', verified: false);
        $missing->employee->forceFill(['joining_date' => null])->save();

        Livewire::actingAs($owner)->test(HistoricalLeaveRecords::class)
            ->assertSee('Annual Leave Setup')
            ->assertSee('Joining Dates Checked')
            ->assertSee('Add Previous Leave')
            ->assertSee('Employee Leave Balances')
            ->assertSee('Enable Annual Leave')
            ->set('joiningDateSearch', 'Employee Needs Date')
            ->assertSee('Not entered')
            ->assertSee(VerifyEmployeeJoiningDate::getUrl(['employee' => $missing->employee->id]), escape: false)
            ->assertSet('joiningDatesChecked', 1);

        $this->actingAs($owner)->get(VerifyEmployeeJoiningDate::getUrl(['employee' => $missing->employee->id]))->assertOk();
        $this->assertNull($missing->employee->fresh()->joining_date);
        $this->assertDatabaseCount('employee_joining_date_audits', 0);
    }

    public function test_employee_search_and_leave_preview_remain_read_only(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $employee = $this->user(EmployeeRole::Staff, 'Previous Leave Employee')->employee;

        Livewire::actingAs($owner)->test(HistoricalLeaveRecords::class)
            ->set('employeeSearch', $employee->employee_id)
            ->assertSee('Previous Leave Employee')
            ->call('selectEmployee', $employee->id)
            ->set('leaveTypeId', LeaveType::query()->where('code', 'annual')->value('id'))
            ->set('fromDate', '2026-08-03')
            ->set('toDate', '2026-08-04')
            ->set('chargeableDays', '2.00')
            ->set('manualOverrideReason', 'Verified from the signed previous register')
            ->assertSet('preview.chargeable_days', '2.00')
            ->assertSee('Leave Preview — 2.00 Leave Days')
            ->assertSee('Save Previous Leave');

        $this->assertDatabaseCount('historical_leave_records', 0);
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public function test_excel_preview_shows_dates_and_keeps_import_confirmation_required(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $employee = $this->user(EmployeeRole::Staff, 'Excel Leave Employee')->employee;
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['Employee ID', 'Actual Joining Date', 'From Date', 'To Date', 'Leave Type', 'Chargeable Days', 'Verified Day Count Justification', 'Approval Status', 'Reason', 'Historical Source'],
            [$employee->employee_id, '2025-01-01', '2026-08-03', '2026-08-04', 'Annual', 2, 'Verified from signed register', 'pending', 'Previously taken annual leave', 'Signed register'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'tpz_leave_ui_');
        // Use the real upload controller with short temporary paths. Livewire's
        // test helper adds fake metadata that exceeds Windows ZipArchive limits.
        $uploadRoot = sys_get_temp_dir().'/tlu-'.bin2hex(random_bytes(4));
        File::makeDirectory($uploadRoot);
        config(['filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => $uploadRoot]]);
        Storage::forgetDisk('tmp-for-tests');
        try {
            (new Xlsx($sheet))->save($path);
            $sourceHash = hash_file('sha256', $path);
            $file = new UploadedFile($path, 'leave.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $component = Livewire::actingAs($owner)->test(HistoricalLeaveRecords::class);
            $filePaths = (new FileUploadController)->validateAndStore([$file], FileUploadConfiguration::disk());
            $storedPath = Storage::disk(FileUploadConfiguration::disk())->path(FileUploadConfiguration::path($filePaths[0]));
            $this->assertSame($sourceHash, hash_file('sha256', $storedPath), 'The stored XLSX upload must preserve the workbook bytes.');
            $component->call('_finishUpload', 'importFile', $filePaths, false);
            $uploadedPath = $component->get('importFile')->getRealPath();
            $this->assertSame($sourceHash, hash_file('sha256', $uploadedPath), 'The Livewire upload must preserve the workbook bytes.');
            $zip = new \ZipArchive;
            $zipResult = $zip->open($uploadedPath);
            $this->assertTrue($zipResult === true, 'ZipArchive upload check: '.(string) $zipResult.'; path length: '.strlen($uploadedPath));
            $zip->close();
            $component->call('previewImport');
            $this->assertEmpty($component->errors()->all(), implode(' ', $component->errors()->all()));
            $component->assertHasNoErrors()
                ->assertSet('importPreview.valid_rows', 1)
                ->assertSee('2026-08-03')
                ->assertSee('Excel Leave Employee')
                ->assertSee('Import Checked Rows')
                ->assertSet('confirmImport', false);
        } finally {
            $sheet->disconnectWorksheets();
            unlink($path);
            Storage::forgetDisk('tmp-for-tests');
            File::deleteDirectory($uploadRoot);
        }
        $this->assertDatabaseCount('historical_leave_records', 0);
        $this->assertDatabaseCount('historical_leave_import_batches', 0);
    }

    public function test_leave_summary_includes_previous_leave_and_erp_pending_days_without_changing_balances(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        app(HistoricalLeaveService::class)->record([
            'employee_id' => $staff->employee->id, 'leave_type_id' => $annual->id,
            'from_date' => '2026-08-03', 'to_date' => '2026-08-04', 'chargeable_days' => '2.00',
            'manual_override_reason' => 'Verified signed register', 'approval_status' => 'approved',
            'reason' => 'Annual leave already taken', 'historical_source' => 'Signed register',
            'confirmed' => true, 'idempotency_key' => fake()->uuid(),
        ], $owner);
        $request = LeaveRequest::query()->create([
            'reference' => 'LEAVE-UI-PENDING', 'employee_id' => $staff->employee->id,
            'leave_type_id' => $annual->id, 'leave_policy_id' => LeavePolicy::query()->value('id'),
            'from_date' => '2026-11-02', 'to_date' => '2026-11-02', 'requested_working_days' => '1.00',
            'status' => 'pending', 'reason' => 'Upcoming leave', 'submitted_at' => now(),
            'idempotency_key' => fake()->uuid(),
        ]);
        LeaveRequestDay::query()->create(['leave_request_id' => $request->id, 'leave_date' => '2026-11-02', 'classification' => 'working_leave', 'counts_as_leave' => true, 'leave_units' => '1.00']);

        Livewire::actingAs($staff)->test(LeaveManagement::class)
            ->assertSee('Leave Used')->assertSee('Waiting for Approval')->assertSee('Days Left')
            ->assertSee('Your annual leave is not ready yet. HR must check your joining date and previous leave records.')
            ->assertSee('Your workdays are not set up yet. Please ask HR to set your work schedule.')
            ->assertViewHas('balance', fn ($balance): bool => $balance->entitlement === '12.00' && $balance->used === '2.00' && $balance->pending === '1.00' && $balance->availableAfterPending === '9.00');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('historical_leave_records', 1);
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public function test_record_permission_does_not_reveal_joining_checks_policy_or_activation_actions(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        foreach ([HrPermission::LeaveHistoricalView, HrPermission::LeaveHistoricalRecord] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $staff->employee->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $staff->id,
                'reason' => 'UI permission regression test',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);

        Livewire::actingAs($staff)->test(HistoricalLeaveRecords::class)
            ->assertSee('Add Previous Leave')
            ->assertDontSee('wire:click="activateAnnualLeaveWorkflow"', escape: false)
            ->assertDontSee('wire:model.live.debounce.300ms="joiningDateSearch"', escape: false)
            ->assertDontSee('Adjust Leave Balance');
        $this->actingAs($staff)->get(LeavePolicySettings::getUrl())->assertForbidden();
        $this->assertDatabaseCount('historical_leave_workflow_activations', 0);
    }

    public function test_previous_records_filter_voided_entries_and_employee_search_resets_balance_pagination(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $employee = $this->user(EmployeeRole::Staff, 'History Filter Employee')->employee;
        $service = app(HistoricalLeaveService::class);
        $payload = [
            'employee_id' => $employee->id, 'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'chargeable_days' => '1.00', 'manual_override_reason' => 'Verified previous register',
            'approval_status' => 'pending', 'reason' => 'Previous annual leave', 'confirmed' => true,
            'historical_source' => 'Signed register',
        ];
        $voided = $service->record($payload + ['from_date' => '2026-08-03', 'to_date' => '2026-08-03', 'idempotency_key' => fake()->uuid()], $owner)->first();
        $service->void($voided, 'Duplicate paper entry identified during review', $owner);
        $service->record($payload + ['from_date' => '2026-08-04', 'to_date' => '2026-08-04', 'idempotency_key' => fake()->uuid()], $owner);

        Livewire::actingAs($owner)->test(HistoricalLeaveRecords::class)
            ->set('historyStatusFilter', 'voided')
            ->assertSet('records', fn ($records): bool => $records->total() === 1 && $records->first()->id === $voided->id)
            ->set('paginators.balancePage', 2)
            ->set('historyEmployeeFilter', $employee->employee_id)
            ->assertSet('paginators.balancePage', 1)
            ->assertSet('records', fn ($records): bool => $records->total() === 1);
    }

    private function user(EmployeeRole $role, ?string $name = null, bool $verified = true): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name ?? fake()->name(), 'email' => $user->email, 'status' => true,
            'joining_date' => '2025-01-01', 'joining_date_verified_at' => $verified ? now() : null,
            'joining_date_verified_by_user_id' => $verified ? $user->id : null,
        ]);

        return $user->refresh();
    }
}
