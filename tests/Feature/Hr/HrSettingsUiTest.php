<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Hr\HrSettings;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\Hr\HrPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HrSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_settings_and_future_change_preserves_historical_policy(): void
    {
        CarbonImmutable::setTestNow('2026-08-21 10:00:00');
        $owner = $this->user(EmployeeRole::Owner);
        $oldAttendance = AttendancePolicy::query()->firstOrFail();
        $oldLeave = LeavePolicy::query()->firstOrFail();

        app(HrPolicyService::class)->createVersion($this->policyData(), $owner);

        $this->assertSame('2026-08-31', $oldAttendance->fresh()->effective_to->toDateString());
        $this->assertSame(5, $oldAttendance->fresh()->grace_minutes);
        $this->assertSame('2026-08-31', $oldLeave->fresh()->effective_to->toDateString());
        $this->assertSame('12.00', $oldLeave->fresh()->annual_leave_entitlement_days);
        $this->assertTrue(AttendancePolicy::query()->whereDate('effective_from', '2026-09-01')->where('grace_minutes', 10)->where('late_after_minutes', 11)->exists());
        $this->assertTrue(LeavePolicy::query()->whereDate('effective_from', '2026-09-01')->where('annual_leave_entitlement_days', 15)->exists());
        $this->actingAs($owner)->get('/admin/hr/settings')->assertOk();
    }

    public function test_staff_cannot_view_or_manage_hr_policy(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->get('/admin/hr/settings')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(HrPolicyService::class)->createVersion($this->policyData(), $staff);
    }

    public function test_settings_livewire_page_surfaces_policy_fields(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        Livewire::actingAs($owner)->test(HrSettings::class)
            ->assertSet('graceMinutes', 5)
            ->assertSet('annualLeaveEntitlementDays', '12.00')
            ->assertSee('Pakistan Office')
            ->assertSee('Asia/Karachi');
    }

    private function policyData(): array
    {
        return [
            'effective_from' => '2026-09-01', 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'grace_minutes' => 10, 'late_occurrences_for_penalty' => 3, 'absence_equivalent_penalty_days' => '1.00',
            'half_day_minimum_minutes' => null, 'annual_leave_entitlement_days' => '15.00',
            'manager_approval_enabled' => false, 'half_day_leave_enabled' => false,
            'compensatory_off_requires_approval' => true, 'compensatory_off_expiry_days' => null,
        ];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
