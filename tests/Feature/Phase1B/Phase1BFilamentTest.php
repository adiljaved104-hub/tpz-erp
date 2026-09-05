<?php

namespace Tests\Feature\Phase1B;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase1BFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_render_supplier_and_warehouse_resources_without_delete_ui(): void
    {
        $owner = $this->userWithRole(EmployeeRole::Owner);

        $this->actingAs($owner)->get('/admin/suppliers')->assertOk()->assertDontSee('Delete selected');
        $this->actingAs($owner)->get('/admin/warehouses')->assertOk()->assertDontSee('Delete selected');
    }

    public function test_manager_has_read_only_resource_access(): void
    {
        $manager = $this->userWithRole(EmployeeRole::Manager);

        $this->actingAs($manager)->get('/admin/suppliers')->assertOk();
        $this->actingAs($manager)->get('/admin/warehouses')->assertOk();
        $this->actingAs($manager)->get('/admin/suppliers/create')->assertForbidden();
        $this->actingAs($manager)->get('/admin/warehouses/create')->assertForbidden();
    }

    public function test_staff_cannot_open_phase_1b_resources(): void
    {
        $staff = $this->userWithRole(EmployeeRole::Staff);

        $this->actingAs($staff)->get('/admin/suppliers')->assertForbidden();
        $this->actingAs($staff)->get('/admin/warehouses')->assertForbidden();
    }

    private function userWithRole(EmployeeRole $role): User
    {
        $user = User::factory()->create([
            'email' => Str::lower($role->value).'-'.Str::lower(Str::random(8)).'@techpointzone.com',
        ]);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
