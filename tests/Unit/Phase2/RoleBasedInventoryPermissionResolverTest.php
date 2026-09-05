<?php

namespace Tests\Unit\Phase2;

use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\RoleBasedInventoryPermissionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleBasedInventoryPermissionResolverTest extends TestCase
{
    #[DataProvider('matrix')]
    public function test_default_permission_matrix(EmployeeRole $role, InventoryPermission $permission, bool $expected): void
    {
        $user = new User;
        $user->setRelation('employee', new Employee(['role' => $role, 'status' => true]));

        $this->assertSame($expected, app(RoleBasedInventoryPermissionResolver::class)->allows($user, $permission));
    }

    public static function matrix(): array
    {
        return [
            [EmployeeRole::Owner, InventoryPermission::View, true],
            [EmployeeRole::Owner, InventoryPermission::PostOpeningStock, true],
            [EmployeeRole::Owner, InventoryPermission::ReverseOpeningStock, false],
            [EmployeeRole::Admin, InventoryPermission::Reserve, true],
            [EmployeeRole::Admin, InventoryPermission::PostOpeningStock, false],
            [EmployeeRole::Admin, InventoryPermission::ViewFinancials, false],
            [EmployeeRole::Manager, InventoryPermission::ViewMovements, true],
            [EmployeeRole::Manager, InventoryPermission::MarkDamaged, false],
            [EmployeeRole::Staff, InventoryPermission::View, false],
        ];
    }
}
