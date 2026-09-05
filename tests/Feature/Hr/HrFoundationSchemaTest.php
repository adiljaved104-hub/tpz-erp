<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Models\BiometricAttendanceEvent;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class HrFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_tables_defaults_and_reference_sequences_are_ready_without_business_history(): void
    {
        foreach ([
            'hr_settings', 'attendance_policies', 'leave_policies', 'work_schedules', 'work_schedule_days', 'work_schedule_assignments', 'public_holidays',
            'leave_types', 'compensatory_offs', 'leave_entitlement_adjustments', 'leave_requests', 'leave_request_days', 'leave_request_events',
            'biometric_employee_identifiers', 'biometric_attendance_events', 'biometric_event_employee_mappings',
            'employee_attendances', 'attendance_evidence_links', 'attendance_corrections',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' is missing.');
        }

        $this->assertDatabaseHas('hr_settings', ['key' => 'annual_leave_entitlement_days', 'value' => '12.00']);
        $this->assertDatabaseHas('attendance_policies', [
            'office_start_time' => '09:00:00', 'office_end_time' => '17:00:00', 'grace_minutes' => 5,
            'late_after_minutes' => 6, 'late_occurrences_for_penalty' => 3,
            'absence_equivalent_penalty_days' => '1.00', 'half_day_minimum_minutes' => null,
        ]);
        $this->assertDatabaseHas('work_schedules', ['code' => 'PK_OFFICE', 'timezone' => 'Asia/Karachi']);
        $this->assertDatabaseHas('leave_policies', [
            'annual_leave_entitlement_days' => '12.00', 'leave_year_mode' => 'calendar_year',
            'manager_approval_enabled' => false, 'compensatory_off_requires_approval' => true,
            'compensatory_off_expiry_days' => null,
        ]);
        $this->assertDatabaseHas('leave_types', ['code' => 'annual', 'consumes_annual_entitlement' => true]);
        $this->assertDatabaseHas('leave_types', ['code' => 'unpaid', 'consumes_annual_entitlement' => false]);
        $this->assertDatabaseHas('leave_types', ['code' => 'compensatory_off', 'consumes_annual_entitlement' => false]);
        foreach (['leave_request:2026', 'compensatory_off:2026', 'leave_adjustment:2026'] as $key) {
            $this->assertDatabaseHas('reference_sequences', ['key' => $key, 'next_value' => 1]);
        }
        $this->assertSame(0, DB::table('leave_requests')->count());
        $this->assertSame(0, DB::table('employee_attendances')->count());
        $this->assertSame(0, DB::table('biometric_attendance_events')->count());
    }

    public function test_schedule_assignment_daily_attendance_and_leave_constraints_are_database_enforced(): void
    {
        [$user, $employee] = $this->employee();
        $scheduleId = DB::table('work_schedules')->insertGetId([
            'code' => 'STANDARD', 'name' => 'Standard', 'timezone' => 'Asia/Dubai', 'schedule_type' => 'standard', 'cycle_length_weeks' => 1,
            'status' => true, 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectQueryFailure(fn () => DB::table('work_schedule_assignments')->insert([
            'work_schedule_id' => $scheduleId, 'employee_id' => $employee->id, 'team_id' => 1,
            'effective_from' => '2026-01-01', 'assigned_by_user_id' => $user->id, 'reason' => 'Invalid dual target',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        DB::table('employee_attendances')->insert([
            'employee_id' => $employee->id, 'attendance_date' => '2026-08-20', 'work_schedule_id' => $scheduleId,
            'status' => 'weekend_off', 'source' => 'system_derived', 'late_minutes' => 0, 'early_departure_minutes' => 0,
            'is_overridden' => false, 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectQueryFailure(fn () => DB::table('employee_attendances')->insert([
            'employee_id' => $employee->id, 'attendance_date' => '2026-08-20', 'status' => 'absent', 'source' => 'system_derived',
            'late_minutes' => 0, 'early_departure_minutes' => 0, 'is_overridden' => false, 'calculated_at' => now(),
        ]));

        $annualType = DB::table('leave_types')->where('code', 'annual')->value('id');
        $leavePolicy = DB::table('leave_policies')->value('id');
        $this->expectQueryFailure(fn () => DB::table('leave_requests')->insert([
            'reference' => 'LVR-2026-000001', 'employee_id' => $employee->id, 'leave_type_id' => $annualType, 'leave_policy_id' => $leavePolicy,
            'from_date' => '2026-08-22', 'to_date' => '2026-08-21', 'requested_working_days' => 1,
            'status' => 'pending', 'reason' => 'Invalid range', 'submitted_at' => now(), 'idempotency_key' => (string) Str::uuid(),
        ]));
    }

    public function test_biometric_import_is_idempotent_and_raw_event_is_immutable(): void
    {
        $attributes = [
            'source' => 'hikvision_future_adapter', 'source_event_id' => 'evt-100', 'external_employee_identifier' => 'BIO-44',
            'device_identifier' => 'device-1', 'punched_at' => now(), 'punch_type' => 'check_in',
            'raw_metadata' => ['door' => 'main'], 'idempotency_key' => (string) Str::uuid(), 'imported_at' => now(), 'created_at' => now(),
        ];
        $event = BiometricAttendanceEvent::query()->create($attributes);
        $this->expectQueryFailure(fn () => DB::table('biometric_attendance_events')->insert($attributes));

        try {
            $event->update(['punch_type' => 'check_out']);
            $this->fail('Raw evidence update was allowed.');
        } catch (LogicException) {
            $this->assertSame('check_in', $event->fresh()->punch_type);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    private function employee(): array
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->for($user)->role(EmployeeRole::Staff)->create(['email' => $user->email]);

        return [$user, $employee];
    }

    private function expectQueryFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
