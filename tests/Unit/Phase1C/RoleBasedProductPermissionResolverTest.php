<?php

namespace Tests\Unit\Phase1C;

use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\RoleBasedProductPermissionResolver;
use PHPUnit\Framework\TestCase;

class RoleBasedProductPermissionResolverTest extends TestCase
{
    public function test_default_roles_are_mapped_in_one_replaceable_resolver(): void
    {
        $resolver = new RoleBasedProductPermissionResolver;

        $admin = $this->user(EmployeeRole::Admin);
        $this->assertTrue($resolver->allows($admin, ProductPermission::EditSellingPrice));
        $this->assertFalse($resolver->allows($admin, ProductPermission::EditCostPrice));
        $this->assertFalse($resolver->allows($admin, ProductPermission::Discontinue));

        $manager = $this->user(EmployeeRole::Manager);
        $this->assertTrue($resolver->allows($manager, ProductPermission::ViewSellingPrice));
        $this->assertTrue($resolver->allows($manager, ProductPermission::Export));
        $this->assertFalse($resolver->allows($manager, ProductPermission::Update));

        $staff = $this->user(EmployeeRole::Staff);
        $this->assertFalse($resolver->allows($staff, ProductPermission::View));
    }

    private function user(EmployeeRole $role): User
    {
        $user = new User;
        $user->setRelation('employee', new Employee(['status' => true, 'role' => $role]));

        return $user;
    }
}
