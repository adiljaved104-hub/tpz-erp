<?php

namespace Tests\Feature\Phase1A;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentUiRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_active_owner_can_open_the_panel_and_employee_create_form_has_no_password(): void
    {
        $owner = $this->userWithRole(EmployeeRole::Owner);

        $this->actingAs($owner)->get('/admin')->assertOk();
        $this->actingAs($owner)->get('/admin/employees/create')
            ->assertOk()
            ->assertDontSee('Employee Password')
            ->assertDontSee('Enter password');
    }

    public function test_product_cost_price_column_is_not_rendered_for_admin(): void
    {
        $admin = $this->userWithRole(EmployeeRole::Admin);

        $this->actingAs($admin)->get('/admin/products')
            ->assertOk()
            ->assertDontSee('Cost Price');
    }

    private function userWithRole(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
