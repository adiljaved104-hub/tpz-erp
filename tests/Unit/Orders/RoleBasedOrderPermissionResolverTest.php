<?php

namespace Tests\Unit\Orders;

use App\Contracts\OrderPermissionGrantResolver;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\NullOrderPermissionGrantResolver;
use App\Services\Authorization\RoleBasedOrderPermissionResolver;
use PHPUnit\Framework\TestCase;

class RoleBasedOrderPermissionResolverTest extends TestCase
{
    public function test_default_roles_keep_fulfilment_denied_for_manager_and_staff(): void
    {
        $resolver = new RoleBasedOrderPermissionResolver(new NullOrderPermissionGrantResolver);

        $this->assertTrue($resolver->allows($this->user(EmployeeRole::Owner, 1), OrderPermission::Fulfill));
        $this->assertTrue($resolver->allows($this->user(EmployeeRole::Admin, 2), OrderPermission::Fulfill));
        $this->assertFalse($resolver->allows($this->user(EmployeeRole::Manager, 3), OrderPermission::Fulfill));
        $this->assertFalse($resolver->allows($this->user(EmployeeRole::Staff, 4), OrderPermission::Fulfill));
    }

    public function test_explicit_grants_enable_only_fulfilment_for_selected_manager_and_staff(): void
    {
        $manager = $this->user(EmployeeRole::Manager, 3);
        $staff = $this->user(EmployeeRole::Staff, 4);
        $grants = new class([$manager->id, $staff->id]) implements OrderPermissionGrantResolver
        {
            /** @param array<int, int> $userIds */
            public function __construct(private readonly array $userIds) {}

            public function decision(User $user, OrderPermission $permission, ?Order $order = null): ?bool
            {
                return $permission === OrderPermission::Fulfill && in_array($user->id, $this->userIds, true) ? true : null;
            }
        };
        $resolver = new RoleBasedOrderPermissionResolver($grants);

        foreach ([$manager, $staff] as $user) {
            $this->assertTrue($resolver->allows($user, OrderPermission::Fulfill));
            $this->assertFalse($resolver->allows($user, OrderPermission::ViewCost));
            $this->assertFalse($resolver->allows($user, OrderPermission::ViewProfit));
        }
    }

    private function user(EmployeeRole $role, int $id): User
    {
        $user = new User;
        $user->forceFill(['id' => $id]);
        $user->setRelation('employee', new Employee(['status' => true, 'role' => $role]));

        return $user;
    }
}
