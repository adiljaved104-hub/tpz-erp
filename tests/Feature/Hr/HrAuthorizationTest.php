<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\HrAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_defaults_and_employee_overrides_use_existing_access_control(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);
        $authorization = app(HrAuthorization::class);

        $this->assertTrue($authorization->allows($owner, HrPermission::AttendanceManagePolicy));
        $this->assertTrue($authorization->allows($manager, HrPermission::AttendanceViewTeam));
        $this->assertFalse($authorization->allows($manager, HrPermission::AttendanceViewAll));
        $this->assertFalse($authorization->allows($manager, HrPermission::LeaveApprove));
        $this->assertTrue($authorization->allows($staff, HrPermission::AttendanceViewOwn));
        $this->assertFalse($authorization->allows($staff, HrPermission::LeaveApprove));

        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id, 'permission_key' => HrPermission::LeaveApprove->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'HR delegation test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertTrue($authorization->allows($staff, HrPermission::LeaveApprove));
        $this->assertNotNull(app(EmployeePermissionCatalog::class)->find(HrPermission::WorkScheduleManage->value));
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
