<?php

namespace Tests\Unit\Responsibilities;

use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityPermission;
use App\Services\Authorization\ResponsibilityAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class RoleBasedResponsibilityPermissionResolverTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_default_permission_matrix_matches_approval(): void
    {
        $authorization = app(ResponsibilityAuthorization::class);

        foreach (ResponsibilityPermission::cases() as $permission) {
            $this->assertTrue($authorization->allows($this->responsibilityUser(EmployeeRole::Owner), $permission));
            $this->assertTrue($authorization->allows($this->responsibilityUser(EmployeeRole::Admin), $permission));
            $this->assertSame(in_array($permission, [ResponsibilityPermission::ViewOwn, ResponsibilityPermission::ViewTeam, ResponsibilityPermission::ViewHistory], true), $authorization->allows($this->responsibilityUser(EmployeeRole::Manager), $permission));
            $this->assertSame($permission === ResponsibilityPermission::ViewOwn, $authorization->allows($this->responsibilityUser(EmployeeRole::Staff), $permission));
        }
    }
}
