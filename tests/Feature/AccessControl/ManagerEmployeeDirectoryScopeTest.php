<?php

namespace Tests\Feature\AccessControl;

use App\Enums\EmployeeRole;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerEmployeeDirectoryScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_directory_and_direct_records_are_limited_to_their_team(): void
    {
        $team = Team::query()->create(['name' => 'Manager Team', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other Team', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager, $team);
        $teammate = $this->user(EmployeeRole::Staff, $team);
        $unrelated = $this->user(EmployeeRole::Staff, $otherTeam);
        $this->actingAs($manager);

        $this->assertEqualsCanonicalizing(
            [$manager->employee->id, $teammate->employee->id],
            EmployeeResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->get(EmployeeResource::getUrl('view', ['record' => $teammate->employee]))->assertOk();
        $this->get(EmployeeResource::getUrl('view', ['record' => $unrelated->employee]))->assertNotFound();
    }

    public function test_owner_and_admin_directory_scope_remains_company_wide(): void
    {
        $team = Team::query()->create(['name' => 'A', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'B', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $admin = $this->user(EmployeeRole::Admin, $team);
        $other = $this->user(EmployeeRole::Staff, $otherTeam);

        foreach ([$owner, $admin] as $actor) {
            $this->actingAs($actor);
            $this->assertTrue(EmployeeResource::getEloquentQuery()->whereKey($other->employee->id)->exists());
            $this->get(EmployeeResource::getUrl('view', ['record' => $other->employee]))->assertOk();
        }
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'team_id' => $team->id,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
