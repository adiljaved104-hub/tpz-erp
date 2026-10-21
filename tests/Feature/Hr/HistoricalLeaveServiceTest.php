<?php

namespace Tests\Feature\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Hr\HistoricalLeaveRecords;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\HistoricalLeaveEvent;
use App\Models\HistoricalLeaveRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hr\HistoricalLeavePreviewService;
use App\Services\Hr\HistoricalLeaveService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HistoricalLeaveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_and_confirmed_manual_entry_is_audited_and_idempotent(): void
    {
        [$owner, $employee] = $this->people();
        $this->configureSchedule($employee, $owner);
        $data = $this->data($employee, '2026-08-03');

        $preview = app(HistoricalLeavePreviewService::class)->preview($data, $owner);
        $this->assertFalse($preview['database_writes']);
        $this->assertSame('1.00', $preview['chargeable_days']);
        $this->assertSame(0, HistoricalLeaveRecord::query()->count());
        $this->assertSame(0, HistoricalLeaveEvent::query()->count());

        $records = app(HistoricalLeaveService::class)->record($data + [
            'confirmed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'historical_source' => 'Signed 2026 leave register',
            'original_reference' => 'HR-LEAVE-0042',
        ], $owner);

        $this->assertCount(1, $records);
        $record = $records->first();
        $this->assertSame('pending', $record->approval_status);
        $this->assertSame('manual', $record->source);
        $this->assertSame('Signed 2026 leave register', $record->historical_source);
        $this->assertSame('HR-LEAVE-0042', $record->original_reference);
        $this->assertSame($owner->id, $record->recorded_by_user_id);
        $this->assertSame(1, $record->days->count());
        $this->assertSame(1, HistoricalLeaveEvent::query()->where('historical_leave_record_id', $record->id)->count());
        $this->assertSame(0, LeaveRequest::query()->where('employee_id', $employee->id)->count());

        $replayed = app(HistoricalLeaveService::class)->record($data + [
            'confirmed' => true,
            'idempotency_key' => $record->record_group_uuid,
            'historical_source' => 'Signed 2026 leave register',
            'original_reference' => 'HR-LEAVE-0042',
        ], $owner);
        $this->assertCount(1, $replayed);
        $this->assertSame($record->id, $replayed->first()->id);
        $this->assertSame(1, HistoricalLeaveRecord::query()->count());
    }

    public function test_approved_historical_leave_reconciles_only_an_absent_attendance_row(): void
    {
        [$owner, $employee] = $this->people();
        $this->configureSchedule($employee, $owner);
        $attendance = EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-03',
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'status' => AttendanceStatus::Absent,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'source' => 'system_derived',
            'is_overridden' => false,
            'calculated_at' => now(),
        ]);
        $data = $this->data($employee, '2026-08-03', 'approved') + [
            'confirmed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'historical_source' => 'Verified paper register',
        ];

        $record = app(HistoricalLeaveService::class)->record($data, $owner)->firstOrFail();

        $this->assertSame('approved', $record->approval_status);
        $this->assertSame($owner->id, $record->approved_by_user_id);
        $this->assertSame(AttendanceStatus::ApprovedLeave, $attendance->fresh()->status);
        $this->assertTrue($attendance->fresh()->is_overridden);
        $this->assertDatabaseHas('attendance_corrections', ['employee_attendance_id' => $attendance->id]);
    }

    public function test_historical_preview_rejects_non_absent_attendance_without_modifying_it(): void
    {
        [$owner, $employee] = $this->people();
        $this->configureSchedule($employee, $owner);
        $attendance = EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-03',
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'source' => 'system_derived',
            'is_overridden' => false,
            'calculated_at' => now(),
        ]);

        try {
            app(HistoricalLeavePreviewService::class)->preview($this->data($employee, '2026-08-03'), $owner);
            $this->fail('Historical leave preview accepted conflicting Present attendance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('from_date', $exception->errors());
        }

        $this->assertSame(AttendanceStatus::Present, $attendance->fresh()->status);
        $this->assertSame(0, HistoricalLeaveRecord::query()->count());
    }

    public function test_missing_schedule_requires_verified_chargeable_days_and_justification(): void
    {
        [$owner, $employee] = $this->people();
        $data = $this->data($employee, '2026-08-03');
        try {
            app(HistoricalLeavePreviewService::class)->preview($data, $owner);
            $this->fail('Historical leave without a schedule was calculated without a verified day count.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('chargeable_days', $exception->errors());
        }

        $data['chargeable_days'] = '1.5';
        $data['manual_override_reason'] = 'Cross-checked against signed register';
        $preview = app(HistoricalLeavePreviewService::class)->preview($data, $owner);
        $this->assertSame('manual_verified', $preview['calculation_method']);
        $this->assertSame('1.50', $preview['chargeable_days']);
        $this->assertSame([], $preview['segments'][0]['daily_rows']);
    }

    public function test_staff_without_historical_record_permission_is_denied_server_side(): void
    {
        [$owner, $employee] = $this->people();
        $staffUser = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create([
            'email' => $staffUser->email, 'status' => true, 'team_id' => $employee->team_id,
            'joining_date' => '2024-01-01', 'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);
        $this->expectException(AuthorizationException::class);
        app(HistoricalLeaveService::class)->record($this->data($employee, '2026-08-03') + [
            'confirmed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'historical_source' => 'Test source',
        ], $staffUser);
    }

    public function test_historical_leave_page_has_an_explicit_confirmation_gate(): void
    {
        [$owner] = $this->people();
        $this->actingAs($owner);

        Livewire::test(HistoricalLeaveRecords::class)
            ->assertSee('Record historical leave')
            ->assertSee('Confirm historical record')
            ->assertSee('I confirm this entry is supported by the stated historical source.')
            ->assertSee('Import historical leave')
            ->assertSee('Download XLSX template')
            ->assertSee('Employee leave balances')
            ->assertSee('Opening balance adjustment')
            ->assertSee('Export current filters');
    }

    /** @return array{User, Employee} */
    private function people(): array
    {
        $owner = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create([
            'email' => $owner->email, 'status' => true, 'joining_date' => '2020-01-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);
        $employeeUser = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($employeeUser)->role(EmployeeRole::Staff)->create([
            'email' => $employeeUser->email, 'status' => true, 'joining_date' => '2025-01-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);

        return [$owner->refresh(), $employee];
    }

    private function configureSchedule(Employee $employee, User $actor): WorkSchedule
    {
        $schedule = WorkSchedule::query()->create([
            'code' => 'HIST_LEAVE_'.strtoupper(fake()->unique()->lexify('??????')),
            'name' => 'Historical leave test schedule', 'timezone' => 'Asia/Karachi', 'schedule_type' => 'standard',
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
            'effective_from' => '2026-01-01', 'assigned_by_user_id' => $actor->id, 'reason' => 'Test-only schedule',
        ]);

        return $schedule;
    }

    /** @return array<string, mixed> */
    private function data(Employee $employee, string $date, string $status = 'pending'): array
    {
        return [
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => $date, 'to_date' => $date,
            'approval_status' => $status,
            'reason' => 'Verified historical leave from original register.',
        ];
    }
}
