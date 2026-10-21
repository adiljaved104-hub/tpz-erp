<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\HistoricalLeaveImportBatch;
use App\Models\HistoricalLeaveRecord;
use App\Models\LeaveCycleAdjustment;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hr\HistoricalLeaveImportService;
use App\Services\Hr\HistoricalLeaveService;
use App\Services\Hr\JoiningDateLeaveBalanceService;
use App\Services\Hr\LeaveCycleAdjustmentService;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HistoricalLeaveImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_confirmed_import_is_atomic_audited_and_one_time(): void
    {
        [$owner, $employee] = $this->people('2025-03-15');
        $this->configureSchedule($employee, $owner);
        $file = $this->workbook([[
            'Employee ID' => $employee->employee_id,
            'Actual Joining Date' => '2025-03-15',
            'From Date' => '2026-03-13',
            'To Date' => '2026-03-16',
            'Leave Type' => 'Annual',
            'Approval Status' => 'pending',
            'Reason' => 'Imported from the signed legacy leave register.',
            'Historical Source' => 'Signed leave register 2025–2026',
            'Original Reference' => 'LEGACY-2026-081',
        ]]);
        $service = app(HistoricalLeaveImportService::class);

        $preview = $service->preview($file, $owner);
        $this->assertSame(1, $preview['total_rows']);
        $this->assertSame(1, $preview['valid_rows']);
        $this->assertSame(0, $preview['invalid_rows']);
        $this->assertSame(0, $preview['duplicate_rows']);
        $this->assertSame('2.00', $preview['estimated_annual_days']);
        $this->assertFalse($preview['database_writes']);
        $this->assertSame(0, HistoricalLeaveImportBatch::query()->count());
        $this->assertSame(0, HistoricalLeaveRecord::query()->count());

        $batch = $service->import($file, $preview['file_sha256'], $owner);
        $this->assertSame('imported', $batch->status);
        $this->assertSame(1, $batch->imported_rows);
        $this->assertSame(2, $batch->records()->count(), 'The dated row crossing the anniversary boundary is split into two correctly attributed cycle records.');
        $importedRows = $batch->records()->orderBy('leave_period_start')->get();
        $this->assertSame(['2025-03-15', '2026-03-15'], $importedRows->map(fn ($record): string => $record->leave_period_start->toDateString())->all());
        $this->assertSame([2, 2], $importedRows->pluck('source_row_number')->all());
        $this->assertSame(['import', 'import'], $importedRows->pluck('source')->all());
        $this->assertSame(['pending', 'pending'], $importedRows->pluck('approval_status')->all());

        $balances = app(JoiningDateLeaveBalanceService::class);
        $this->assertSame('12.00', $balances->forDate($employee, '2026-03-14')->entitlement);
        $this->assertSame('1.00', $balances->forDate($employee, '2026-03-14')->pending);
        $this->assertSame('12.00', $balances->forDate($employee, '2026-03-15')->entitlement);
        $this->assertSame('1.00', $balances->forDate($employee, '2026-03-15')->pending);

        try {
            $service->import($file, $preview['file_sha256'], $owner);
            $this->fail('The same workbook was imported a second time.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('importFile', $exception->errors());
        }
        $this->assertSame(1, HistoricalLeaveImportBatch::query()->where('status', 'imported')->count());
        $this->assertSame(2, HistoricalLeaveRecord::query()->count());

        try {
            app(LeaveCycleAdjustmentService::class)->record([
                'employee_id' => $employee->id, 'leave_period_start' => '2025-03-15', 'leave_period_end' => '2026-03-14',
                'adjustment_type' => 'credit', 'days' => '1', 'reason' => 'Opening balance correction',
                'supporting_reference' => 'OPENING-REF-1', 'idempotency_key' => fake()->uuid(), 'confirmed' => true,
            ], $owner);
            $this->fail('An opening adjustment duplicated a cycle already represented by imported history.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('leave_period_start', $exception->errors());
        }
        $this->assertSame(0, LeaveCycleAdjustment::query()->count());
    }

    public function test_duplicate_rows_in_workbook_block_the_whole_import_without_writes(): void
    {
        [$owner, $employee] = $this->people('2025-01-01');
        $this->configureSchedule($employee, $owner);
        $row = [
            'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2025-01-01',
            'From Date' => '2026-08-03', 'To Date' => '2026-08-03', 'Leave Type' => 'Annual',
            'Approval Status' => 'pending', 'Reason' => 'Imported leave record.', 'Historical Source' => 'Legacy register',
        ];
        $file = $this->workbook([$row, $row]);
        $service = app(HistoricalLeaveImportService::class);
        $preview = $service->preview($file, $owner);

        $this->assertSame(1, $preview['valid_rows']);
        $this->assertSame(1, $preview['duplicate_rows']);
        try {
            $service->import($file, $preview['file_sha256'], $owner);
            $this->fail('A workbook with a duplicate row was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('importFile', $exception->errors());
        }
        $this->assertSame(0, HistoricalLeaveImportBatch::query()->count());
        $this->assertSame(0, HistoricalLeaveRecord::query()->count());
    }

    public function test_import_detects_an_existing_manual_record_even_when_that_record_is_not_active(): void
    {
        [$owner, $employee] = $this->people('2024-01-01');
        $this->configureSchedule($employee, $owner);
        $existingRecords = app(HistoricalLeaveService::class)->record([
            'employee_id' => $employee->id, 'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => '2025-12-31', 'to_date' => '2026-01-02', 'approval_status' => 'cancelled',
            'reason' => 'Prior record retained for duplicate-prevention verification.',
            'historical_source' => 'Prior register', 'idempotency_key' => fake()->uuid(), 'confirmed' => true,
        ], $owner);
        $this->assertSame(2, $existingRecords->count(), 'The prior cancelled request spans two anniversary-cycle records.');
        $file = $this->workbook([[
            'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2024-01-01',
            'From Date' => '2025-12-31', 'To Date' => '2026-01-02', 'Leave Type' => 'Annual',
            'Approval Status' => 'pending', 'Reason' => 'Re-imported duplicate.', 'Historical Source' => 'Legacy register',
        ]]);
        $service = app(HistoricalLeaveImportService::class);
        $preview = $service->preview($file, $owner);

        $this->assertSame('duplicate', $preview['rows'][0]['status'], json_encode($preview['rows'][0]['errors']));
        $this->assertSame(0, $preview['valid_rows']);
        $this->assertSame(1, $preview['duplicate_rows']);
        try {
            $service->import($file, $preview['file_sha256'], $owner);
            $this->fail('A duplicate of an existing historical record was imported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('importFile', $exception->errors());
        }
        $this->assertSame(2, HistoricalLeaveRecord::query()->count());
        $this->assertSame(0, HistoricalLeaveImportBatch::query()->count());
    }

    public function test_combined_pending_annual_rows_cannot_exceed_the_12_day_anniversary_entitlement(): void
    {
        [$owner, $employee] = $this->people('2025-01-01');
        $this->configureSchedule($employee, $owner);
        $file = $this->workbook([
            [
                'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2025-01-01',
                'From Date' => '2026-08-03', 'To Date' => '2026-08-11', 'Leave Type' => 'Annual',
                'Approval Status' => 'pending', 'Reason' => 'First pending historical period.', 'Historical Source' => 'Legacy register',
            ],
            [
                'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2025-01-01',
                'From Date' => '2026-08-12', 'To Date' => '2026-08-20', 'Leave Type' => 'Annual',
                'Approval Status' => 'pending', 'Reason' => 'Second pending historical period.', 'Historical Source' => 'Legacy register',
            ],
        ]);

        $preview = app(HistoricalLeaveImportService::class)->preview($file, $owner);

        $this->assertSame('12.00', (string) LeavePolicy::query()->where('status', true)->whereNull('effective_to')->latest('effective_from')->value('annual_leave_entitlement_days'));
        $this->assertSame(1, $preview['valid_rows']);
        $this->assertSame(1, $preview['invalid_rows']);
        $this->assertStringContainsString('Combined pending Annual Leave', implode(' ', $preview['rows'][1]['errors']));
        $this->assertSame(0, HistoricalLeaveRecord::query()->count());
    }

    public function test_approved_import_keeps_original_approval_metadata_without_fabricating_an_erp_approver(): void
    {
        [$owner, $employee] = $this->people('2025-01-01');
        $this->configureSchedule($employee, $owner);
        $file = $this->workbook([[
            'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2025-01-01',
            'From Date' => '2026-08-03', 'To Date' => '2026-08-03', 'Leave Type' => 'Annual',
            'Approval Status' => 'approved', 'Reason' => 'Previously approved before ERP migration.',
            'Historical Source' => 'Signed historical register', 'Original Reference' => 'LEGACY-APR-019',
            'Original Approval Date' => '2026-04-01', 'Original Approver' => 'Previous HR Manager',
        ]]);
        $service = app(HistoricalLeaveImportService::class);
        $preview = $service->preview($file, $owner);
        $this->assertSame(1, $preview['valid_rows']);

        $service->import($file, $preview['file_sha256'], $owner);
        $record = HistoricalLeaveRecord::query()->sole();
        $this->assertSame('approved', $record->approval_status);
        $this->assertNull($record->approved_by_user_id);
        $this->assertSame('2026-04-01', $record->original_approved_at->toDateString());
        $this->assertSame('Previous HR Manager', $record->original_approver);
        $this->assertSame('LEGACY-APR-019', $record->original_reference);
        $this->assertDatabaseHas('historical_leave_events', [
            'historical_leave_record_id' => $record->id, 'event_type' => 'imported', 'source' => 'import',
        ]);
    }

    public function test_actual_joining_date_must_match_verified_employee_and_invalid_batch_cannot_partially_import(): void
    {
        [$owner, $employee] = $this->people('2025-01-01');
        $this->configureSchedule($employee, $owner);
        $file = $this->workbook([
            [
                'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2024-12-31',
                'From Date' => '2026-08-03', 'To Date' => '2026-08-03', 'Leave Type' => 'Annual',
                'Approval Status' => 'pending', 'Reason' => 'Incorrect identity row.', 'Historical Source' => 'Legacy register',
            ],
            [
                'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2025-01-01',
                'From Date' => '2026-08-04', 'To Date' => '2026-08-04', 'Leave Type' => 'Annual',
                'Approval Status' => 'pending', 'Reason' => 'Valid second row.', 'Historical Source' => 'Legacy register',
            ],
        ]);
        $service = app(HistoricalLeaveImportService::class);
        $preview = $service->preview($file, $owner);

        $this->assertSame(1, $preview['valid_rows']);
        $this->assertSame(1, $preview['invalid_rows']);
        $this->assertStringContainsString('does not match', implode(' ', $preview['rows'][0]['errors']));
        try {
            $service->import($file, $preview['file_sha256'], $owner);
            $this->fail('An invalid row was silently omitted and the remainder imported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('importFile', $exception->errors());
        }
        $this->assertSame(0, HistoricalLeaveImportBatch::query()->count());
        $this->assertSame(0, HistoricalLeaveRecord::query()->count());
    }

    public function test_import_requires_its_specific_permission_and_never_uses_only_record_permission(): void
    {
        [$owner, $employee] = $this->people('2025-01-01');
        $staff = $this->user(EmployeeRole::Staff);
        foreach ([HrPermission::LeaveHistoricalRecord, HrPermission::LeaveViewAll] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $staff->employee->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
                'reason' => 'Historical leave permission-scope regression test',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->configureSchedule($employee, $owner);
        $file = $this->workbook([[
            'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2025-01-01',
            'From Date' => '2026-08-03', 'To Date' => '2026-08-03', 'Leave Type' => 'Annual',
            'Approval Status' => 'pending', 'Reason' => 'Restricted importer test.', 'Historical Source' => 'Legacy register',
        ]]);
        $this->expectException(AuthorizationException::class);
        app(HistoricalLeaveImportService::class)->preview($file, $staff);
    }

    public function test_downloadable_template_is_a_valid_xlsx_with_the_required_header_contract(): void
    {
        [$owner] = $this->people('2025-01-01');
        $response = app(HistoricalLeaveImportService::class)->downloadTemplate($owner);
        ob_start();
        $response->sendContent();
        $contents = (string) ob_get_clean();
        $this->assertStringStartsWith('PK', $contents);

        $path = tempnam(sys_get_temp_dir(), 'historical_template_');
        if (! is_string($path)) {
            throw new \RuntimeException('Could not allocate a temporary test template.');
        }
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);
        $workbook = IOFactory::load($path);
        $sheet = $workbook->getSheetByName('Historical Leave');
        $this->assertNotNull($sheet);
        $this->assertSame('Employee ID', $sheet->getCell('A1')->getValue());
        $this->assertSame('Actual Joining Date', $sheet->getCell('B1')->getValue());
        $this->assertSame('Original Reference', $sheet->getCell('K1')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_manager_import_permission_is_limited_to_their_authorized_team(): void
    {
        [$owner] = $this->people('2020-01-01');
        $team = Team::query()->create(['name' => 'Historical Import Team', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager);
        $manager->employee->forceFill(['team_id' => $team->id])->save();
        $employee = $this->employeeInTeam($owner, $team->id, '2025-01-01');
        $outsideTeam = Team::query()->create(['name' => 'Outside Import Team', 'status' => true]);
        $outside = $this->employeeInTeam($owner, $outsideTeam->id, '2025-01-01');
        $this->configureSchedule($employee, $owner);
        $this->configureSchedule($outside, $owner);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id, 'permission_key' => HrPermission::LeaveHistoricalImport->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
            'reason' => 'Authorized import scope regression test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);

        $insideFile = $this->workbook([[
            'Employee ID' => $employee->employee_id, 'Actual Joining Date' => '2025-01-01',
            'From Date' => '2026-08-03', 'To Date' => '2026-08-03', 'Leave Type' => 'Annual',
            'Approval Status' => 'pending', 'Reason' => 'Authorized team historical record.', 'Historical Source' => 'Team leave register',
        ]]);
        $insidePreview = app(HistoricalLeaveImportService::class)->preview($insideFile, $manager);
        $this->assertSame(1, $insidePreview['valid_rows']);

        $outsideFile = $this->workbook([[
            'Employee ID' => $outside->employee_id, 'Actual Joining Date' => '2025-01-01',
            'From Date' => '2026-08-03', 'To Date' => '2026-08-03', 'Leave Type' => 'Annual',
            'Approval Status' => 'pending', 'Reason' => 'Out-of-team row.', 'Historical Source' => 'Team leave register',
        ]]);
        $outsidePreview = app(HistoricalLeaveImportService::class)->preview($outsideFile, $manager);
        $this->assertSame(0, $outsidePreview['valid_rows']);
        $this->assertSame(1, $outsidePreview['invalid_rows']);
        $this->assertNull($outsidePreview['rows'][0]['employee_code'], 'Out-of-scope identity is not returned in the preview.');
        $this->assertSame(0, HistoricalLeaveRecord::query()->count());
    }

    public function test_opening_adjustment_is_audited_scoped_and_duplicate_supporting_reference_is_rejected(): void
    {
        [$owner, $employee] = $this->people('2025-01-01');
        $service = app(LeaveCycleAdjustmentService::class);
        $payload = [
            'employee_id' => $employee->id, 'leave_period_start' => '2026-01-01', 'leave_period_end' => '2026-12-31',
            'adjustment_type' => 'credit', 'days' => '2.00', 'reason' => 'Verified opening balance from signed ledger.',
            'supporting_reference' => 'OPENING-2026-001', 'idempotency_key' => fake()->uuid(), 'confirmed' => true,
        ];

        $cycle = app(AnnualLeaveCycleResolver::class)->forDate($employee, '2026-04-01');
        $payload['leave_period_start'] = $cycle->start->toDateString();
        $payload['leave_period_end'] = $cycle->end->toDateString();
        $adjustment = $service->record($payload, $owner);

        $this->assertDatabaseHas('leave_cycle_adjustments', [
            'id' => $adjustment->id, 'employee_id' => $employee->id, 'adjustment_type' => 'credit',
            'days' => '2.00', 'recorded_by_user_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => $adjustment->getMorphClass(), 'subject_id' => $adjustment->id, 'event' => 'hr.leave_cycle_balance_adjusted']);
        $balance = app(JoiningDateLeaveBalanceService::class)->forDate($employee, '2026-04-01');
        $this->assertSame('12.00', $balance->entitlement);
        $this->assertSame('2.00', $balance->adjustments);
        $this->assertSame('14.00', $balance->remaining);

        $payload['idempotency_key'] = fake()->uuid();
        try {
            $service->record($payload, $owner);
            $this->fail('A duplicate supporting reference changed the same opening balance twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supporting_reference', $exception->errors());
        }
        $this->assertSame(1, LeaveCycleAdjustment::query()->count());

        $staff = $this->user(EmployeeRole::Staff);
        try {
            $payload['idempotency_key'] = fake()->uuid();
            $service->record($payload, $staff);
            $this->fail('A staff user without the balance-adjust permission created an opening adjustment.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, LeaveCycleAdjustment::query()->count());
    }

    /** @return array{User,Employee} */
    private function people(string $joiningDate): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $employeeUser = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($employeeUser)->role(EmployeeRole::Staff)->create([
            'email' => $employeeUser->email, 'status' => true, 'joining_date' => $joiningDate,
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);

        return [$owner, $employee];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function configureSchedule(Employee $employee, User $actor): void
    {
        $schedule = WorkSchedule::query()->create([
            'code' => 'HIST_IMPORT_'.strtoupper(fake()->unique()->lexify('??????')),
            'name' => 'Historical import test schedule', 'timezone' => 'Asia/Karachi', 'schedule_type' => 'standard',
            'cycle_length_weeks' => 1, 'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => true,
        ]);
        foreach (range(0, 6) as $weekday) {
            $working = ! in_array($weekday, [0, 6], true);
            WorkScheduleDay::query()->create([
                'work_schedule_id' => $schedule->id, 'cycle_week' => 1, 'weekday' => $weekday,
                'is_working_day' => $working, 'expected_start_time' => $working ? '09:00' : null,
                'expected_end_time' => $working ? '17:00' : null, 'earns_compensatory_off' => false,
            ]);
        }
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id, 'employee_id' => $employee->id,
            'effective_from' => '2024-01-01', 'assigned_by_user_id' => $actor->id, 'reason' => 'Test-only schedule',
        ]);
    }

    private function employeeInTeam(User $verifier, int $teamId, string $joiningDate): Employee
    {
        $employeeUser = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);

        return Employee::factory()->for($employeeUser)->role(EmployeeRole::Staff)->create([
            'email' => $employeeUser->email, 'status' => true, 'team_id' => $teamId,
            'joining_date' => $joiningDate, 'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $verifier->id,
        ]);
    }

    /** @param array<int,array<string,string>> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $headers = [...HistoricalLeaveImportService::REQUIRED_HEADERS, ...HistoricalLeaveImportService::OPTIONAL_HEADERS];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $offset => $values) {
            $sheet->fromArray(array_map(fn (string $header): ?string => $values[$header] ?? null, $headers), null, 'A'.($offset + 2));
        }
        $path = tempnam(sys_get_temp_dir(), 'historical_leave_');
        if (! is_string($path)) {
            throw new \RuntimeException('Could not allocate a temporary test workbook.');
        }
        $this->temporaryFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'historical_leave.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
