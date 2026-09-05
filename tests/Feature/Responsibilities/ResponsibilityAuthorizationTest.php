<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\EmployeeRole;
use App\Models\Team;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_owner_admin_manage_manager_sees_team_and_staff_sees_only_own(): void
    {
        $team = Team::query()->create(['name' => 'E-commerce', 'status' => true]);
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $manager = $this->responsibilityUser(EmployeeRole::Manager, $team);
        $staff = $this->responsibilityUser(EmployeeRole::Staff, $team);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $f = $this->responsibilityFoundation();
        $f['owner'] = $owner;
        $f['employee'] = $staff->employee;
        $staffAssignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $owner);
        $f['employee'] = $other->employee;
        $otherAssignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $owner);
        $read = app(ResponsibilityReadService::class);

        $this->assertTrue($read->assignmentsFor($manager)->whereKey($staffAssignment)->exists());
        $this->assertFalse($read->assignmentsFor($manager)->whereKey($otherAssignment)->exists());
        $this->assertTrue($read->assignmentsFor($staff)->whereKey($staffAssignment)->exists());
        $this->assertFalse($read->assignmentsFor($staff)->whereKey($otherAssignment)->exists());

        $this->expectException(AuthorizationException::class);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $staff->employee->id]), $staff);
    }
}
