<?php

namespace Tests\Feature\Phase1B;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase1BPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_manage_manager_reads_and_staff_has_no_access(): void
    {
        $supplier = Supplier::factory()->create();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $owner = $this->userWithRole(EmployeeRole::Owner);
        $admin = $this->userWithRole(EmployeeRole::Admin);
        $manager = $this->userWithRole(EmployeeRole::Manager);
        $staff = $this->userWithRole(EmployeeRole::Staff);

        $this->assertTrue($owner->can('update', $supplier));
        $this->assertTrue($admin->can('changeDefault', $warehouse));
        $this->assertTrue($manager->can('view', $supplier));
        $this->assertTrue($manager->can('view', $warehouse));
        $this->assertFalse($manager->can('update', $supplier));
        $this->assertFalse($manager->can('changeDefault', $warehouse));
        $this->assertFalse($staff->can('viewAny', Supplier::class));
        $this->assertFalse($staff->can('viewAny', Warehouse::class));
        $this->assertFalse($owner->can('delete', $supplier));
        $this->assertFalse($owner->can('delete', $warehouse));
    }

    private function userWithRole(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
