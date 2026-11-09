<?php

namespace Tests\Feature\Mobile;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeRole;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAttendanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_attendance_is_private_and_read_only_without_correction_permission(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $other = $this->user(EmployeeRole::Staff, $team);
        $own = $this->attendance($staff->employee, '2026-11-03', AttendanceStatus::Late);
        $outside = $this->attendance($other->employee, '2026-11-03', AttendanceStatus::Present);

        $this->as($staff)->getJson('/api/mobile/v1/workspace/hr')
            ->assertOk()->assertJsonFragment(['key' => 'attendance', 'title' => 'Attendance']);
        $this->as($staff)->getJson('/api/mobile/v1/workspace/search?q=attendance')
            ->assertOk()->assertJsonFragment([
                'target' => ['module' => 'hr/attendance', 'id' => 0],
            ]);
        $this->as($staff)->getJson('/api/mobile/v1/workspace/hr/attendance')
            ->assertOk()
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['id' => $outside->id]);
        $this->as($staff)->getJson("/api/mobile/v1/workspace/hr/attendance/{$own->id}")
            ->assertOk()->assertJsonPath('data.actions', []);
        $this->as($staff)->getJson("/api/mobile/v1/workspace/hr/attendance/{$outside->id}")
            ->assertNotFound();
        $this->as($staff)->postJson("/api/mobile/v1/workspace/hr/attendance/{$own->id}/correct", [
            'status' => 'present', 'late_minutes' => 0, 'early_departure_minutes' => 0,
            'reason' => 'Unauthorized attempt',
        ])->assertForbidden();
    }

    public function test_owner_correction_uses_existing_audited_domain_service(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $attendance = $this->attendance($staff->employee, '2026-11-03', AttendanceStatus::Absent);

        $this->as($owner)->getJson("/api/mobile/v1/workspace/hr/attendance/{$attendance->id}")
            ->assertOk()
            ->assertJsonFragment(['key' => 'correct'])
            ->assertJsonPath('data.fields.is_overridden', false);

        $this->as($owner)->postJson("/api/mobile/v1/workspace/hr/attendance/{$attendance->id}/correct", [
            'status' => 'present',
            'first_check_in_at' => '2026-11-03T09:00:00+05:00',
            'last_check_out_at' => '2026-11-03T17:00:00+05:00',
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'reason' => 'Owner verified missing biometric punches',
        ])->assertOk()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.fields.is_overridden', true);

        $this->assertDatabaseHas('attendance_corrections', [
            'employee_attendance_id' => $attendance->id,
            'actor_user_id' => $owner->id,
            'reason' => 'Owner verified missing biometric punches',
        ]);
        $this->assertDatabaseHas('employee_attendances', [
            'id' => $attendance->id,
            'status' => 'present',
            'is_overridden' => true,
        ]);
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile attendance test')->plainTextToken);
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team->id, 'status' => true,
        ]);

        return $user->refresh();
    }

    private function attendance(Employee $employee, string $date, AttendanceStatus $status): EmployeeAttendance
    {
        return EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'),
            'status' => $status,
            'late_minutes' => $status === AttendanceStatus::Late ? 10 : 0,
            'early_departure_minutes' => 0,
            'source' => 'system_derived',
            'is_overridden' => false,
            'calculated_at' => now(),
        ]);
    }
}
