<?php

namespace Tests\Feature\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendancePolicy;
use App\Models\BiometricAttendanceEvent;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceQueryService;
use App\Services\Hr\HrScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_sees_only_own_attendance_and_cannot_open_another_employee_record(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $other = $this->user(EmployeeRole::Staff, $team);
        $own = $this->attendance($staff->employee, AttendanceStatus::Present);
        $otherRow = $this->attendance($other->employee, AttendanceStatus::Late, '2026-08-20');

        $query = app(AttendanceQueryService::class)->query($staff, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
        $this->assertEquals([$own->id], $query->pluck('id')->all());
        $this->actingAs($staff)->get('/admin/hr/attendance')->assertOk()->assertSee('My Attendance')->assertDontSee($other->employee->name);
        $this->assertFalse(app(HrScopeService::class)->canViewAttendance($staff, $other->employee));
        $this->assertNotEquals($own->id, $otherRow->id);
    }

    public function test_manager_is_team_scoped_and_owner_sees_company_attendance(): void
    {
        $teamA = Team::query()->create(['name' => 'A', 'status' => true]);
        $teamB = Team::query()->create(['name' => 'B', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager, $teamA);
        $owner = $this->user(EmployeeRole::Owner, $teamB);
        $a = $this->user(EmployeeRole::Staff, $teamA);
        $b = $this->user(EmployeeRole::Staff, $teamB);
        $this->attendance($a->employee, AttendanceStatus::Present);
        $this->attendance($b->employee, AttendanceStatus::Present);
        $from = CarbonImmutable::parse('2026-08-01');
        $to = CarbonImmutable::parse('2026-08-31');

        $this->assertSame(1, app(AttendanceQueryService::class)->query($manager, $from, $to)->count());
        $this->assertSame(2, app(AttendanceQueryService::class)->query($owner, $from, $to)->count());
    }

    public function test_three_lates_produce_one_penalty_without_changing_late_status(): void
    {
        $staff = $this->user(EmployeeRole::Staff, Team::query()->create(['name' => 'A', 'status' => true]));
        foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $date) {
            $this->attendance($staff->employee, AttendanceStatus::Late, $date);
        }
        $query = app(AttendanceQueryService::class)->query($staff, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
        $summary = app(AttendanceQueryService::class)->summary(clone $query);

        $this->assertSame(3, $summary['late']);
        $this->assertSame('1.00', $summary['late_penalty']);
        $this->assertSame(0, $summary['absent']);
        $this->assertSame(3, EmployeeAttendance::query()->where('status', 'late')->count());
    }

    public function test_authorized_correction_preserves_raw_biometric_evidence_and_is_audited(): void
    {
        $team = Team::query()->create(['name' => 'A', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $attendance = $this->attendance($staff->employee, AttendanceStatus::Absent);
        $attendance->forceFill(['early_departure_minutes' => 45])->save();
        $event = BiometricAttendanceEvent::query()->create([
            'source' => 'test', 'source_event_id' => 'evt-1', 'external_employee_identifier' => 'BIO-1',
            'punched_at' => '2026-08-20 09:10:00', 'punch_type' => 'check_in', 'idempotency_key' => (string) Str::uuid(),
            'imported_at' => now(), 'created_at' => now(),
        ]);
        DB::table('attendance_evidence_links')->insert(['employee_attendance_id' => $attendance->id, 'biometric_attendance_event_id' => $event->id, 'created_at' => now()]);
        $before = $event->fresh()->only(['source', 'source_event_id', 'external_employee_identifier', 'punched_at', 'punch_type', 'raw_metadata', 'idempotency_key', 'imported_at']);

        app(AttendanceCorrectionService::class)->correct($attendance, [
            'status' => 'present', 'first_check_in_at' => '2026-08-20 09:00:00',
            'last_check_out_at' => '2026-08-20 17:00:00', 'late_minutes' => 0,
            'early_departure_minutes' => 15,
        ], 'Missing checkout verified by Owner', $owner);

        $this->assertSame('present', $attendance->fresh()->status->value);
        $this->assertTrue($attendance->fresh()->is_overridden);
        $this->assertDatabaseCount('attendance_corrections', 1);
        $this->assertSame(15, $attendance->fresh()->early_departure_minutes);
        $correction = AttendanceCorrection::query()->sole();
        $this->assertSame(45, $correction->old_values['early_departure_minutes']);
        $this->assertSame(15, $correction->new_values['early_departure_minutes']);
        $this->assertEquals($before, $event->fresh()->only(array_keys($before)));
    }

    public function test_staff_without_correction_permission_is_denied(): void
    {
        $team = Team::query()->create(['name' => 'A', 'status' => true]);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $attendance = $this->attendance($staff->employee, AttendanceStatus::Absent);

        $this->expectException(AuthorizationException::class);
        app(AttendanceCorrectionService::class)->correct($attendance, ['status' => 'present', 'late_minutes' => 0], 'Not allowed', $staff);
    }

    private function attendance(Employee $employee, AttendanceStatus $status, string $date = '2026-08-21'): EmployeeAttendance
    {
        return EmployeeAttendance::query()->create([
            'employee_id' => $employee->id, 'attendance_date' => $date,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => $status,
            'late_minutes' => $status === AttendanceStatus::Late ? 10 : 0, 'early_departure_minutes' => 0,
            'source' => 'system_derived', 'is_overridden' => false, 'calculated_at' => now(),
        ]);
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $team->id, 'status' => true]);

        return $user->refresh();
    }
}
