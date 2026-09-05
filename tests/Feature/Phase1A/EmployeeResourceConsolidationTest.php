<?php

namespace Tests\Feature\Phase1A;

use App\Enums\EmployeeRole;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeResourceConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_resource_owns_creation_and_separate_navigation_is_absent(): void
    {
        $this->actingAs($this->owner());
        $this->get(EmployeeResource::getUrl())->assertOk();

        Livewire::test(ListEmployees::class)
            ->assertActionExists('create');

        $navigationLabels = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item): string => $item->getLabel())
            ->all();

        $this->assertContains('Employees', $navigationLabels);
        $this->assertContains('Activity Logs', $navigationLabels);
        $this->assertNotContains('New Login & Employee', $navigationLabels);
        $this->assertFileDoesNotExist(app_path('Filament/Pages/People/CreateUserAndEmployee.php'));
    }

    public function test_view_page_shows_login_identity_without_authentication_secrets(): void
    {
        $this->actingAs($this->owner());
        $login = User::factory()->create([
            'name' => 'Admin Test',
            'email' => 'testadmin@gmail.com',
        ]);
        $employee = Employee::factory()->for($login)->role(EmployeeRole::Admin)->create([
            'email' => $login->email,
        ]);

        Livewire::test(ViewEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertSee('Login Name')
            ->assertSee('Admin Test')
            ->assertSee('Login Email')
            ->assertSee('testadmin@gmail.com')
            ->assertSee('Account Status')
            ->assertDontSee($login->password);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create([
            'email' => $user->email,
        ]);

        return $user->refresh();
    }
}
