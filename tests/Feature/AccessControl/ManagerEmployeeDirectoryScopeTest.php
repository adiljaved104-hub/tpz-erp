<?php

namespace Tests\Feature\AccessControl;

use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PeoplePermission;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_teamless_manager_list_and_search_show_only_self_not_other_unassigned_employees(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $unassigned = $this->user(EmployeeRole::Staff);
        $otherUnassigned = $this->user(EmployeeRole::Manager);
        $team = Team::query()->create(['name' => 'Assigned Team', 'status' => true]);
        $assigned = $this->user(EmployeeRole::Staff, $team);
        $this->actingAs($manager);

        $this->assertSame([$manager->employee->id], EmployeeResource::getEloquentQuery()->pluck('id')->all());
        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$manager->employee])
            ->assertCanNotSeeTableRecords([$unassigned->employee, $otherUnassigned->employee, $assigned->employee]);
        foreach ([$unassigned, $otherUnassigned, $assigned] as $other) {
            $this->assertFalse($manager->can('view', $other->employee));
            $this->assertTrue($this->searchIds($manager, $other->employee->employee_id) === []);
        }
        $this->assertTrue($manager->can('view', $manager->employee));
        $this->assertSame([$manager->employee->id], $this->searchIds($manager, $manager->employee->employee_id));
        $this->get(EmployeeResource::getUrl('view', ['record' => $unassigned->employee]))->assertNotFound();
        $this->get(EmployeeResource::getUrl('view', ['record' => $manager->employee]))->assertOk();
    }

    public function test_directory_and_search_have_equivalent_visibility_for_all_roles_and_null_teams(): void
    {
        $team = Team::query()->create(['name' => 'Team One', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Team Two', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $manager = $this->user(EmployeeRole::Manager, $team);
        $teamlessManager = $this->user(EmployeeRole::Manager);
        $teammate = $this->user(EmployeeRole::Staff, $team);
        $outsider = $this->user(EmployeeRole::Staff, $otherTeam);
        $unassigned = $this->user(EmployeeRole::Staff);
        $allIds = Employee::query()->pluck('id')->all();

        foreach ([
            [$owner, $allIds],
            [$admin, $allIds],
            [$manager, [$manager->employee->id, $teammate->employee->id]],
            [$teamlessManager, [$teamlessManager->employee->id]],
        ] as [$actor, $expected]) {
            $this->actingAs($actor);
            $this->assertEqualsCanonicalizing($expected, EmployeeResource::getEloquentQuery()->pluck('id')->all());
            foreach ([$owner, $admin, $manager, $teamlessManager, $teammate, $outsider, $unassigned] as $target) {
                $id = $target->employee->id;
                $this->assertSame(in_array($id, $expected, true), EmployeeResource::getEloquentQuery()->whereKey($id)->exists());
                $this->assertSame(in_array($id, $expected, true) ? [$id] : [], $this->searchIds($actor, $target->employee->employee_id));
                $this->assertSame(in_array($id, $expected, true), $actor->can('view', $target->employee));
            }
        }
    }

    public function test_staff_defaults_and_explicit_employee_view_override_are_unchanged(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $this->assertFalse($staff->can('viewAny', Employee::class));
        $this->assertTrue($staff->can('view', $staff->employee));
        $this->assertFalse($staff->can('view', $other->employee));
        $this->assertSame([], $this->searchIds($staff, $other->employee->employee_id));
        $this->actingAs($staff)->get(EmployeeResource::getUrl())->assertForbidden();
        $this->get(EmployeeResource::getUrl('view', ['record' => $staff->employee]))->assertForbidden();

        app(EmployeePermissionOverrideService::class)->change($staff->employee, PeoplePermission::EmployeeView->value, EmployeePermissionEffect::Allow, null, $owner);
        $this->assertTrue($staff->can('viewAny', Employee::class));
        $this->assertTrue($staff->can('view', $other->employee));
        $this->assertTrue(EmployeeResource::getEloquentQuery()->whereKey($other->employee->id)->exists());
        $this->assertSame([$other->employee->id], $this->searchIds($staff, $other->employee->employee_id));
    }

    public function test_denied_employee_view_permission_is_not_bypassed_by_directory_scope(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        app(EmployeePermissionOverrideService::class)->change($manager->employee, PeoplePermission::EmployeeView->value, EmployeePermissionEffect::Deny, null, $owner);

        $this->assertSame([], $this->searchIds($manager, $manager->employee->employee_id));
        $this->actingAs($manager)->get(EmployeeResource::getUrl())->assertForbidden();
        $this->assertTrue($manager->can('view', $manager->employee));
    }

    private function searchIds(User $actor, string $term): array
    {
        return app(GlobalSearchService::class)->search($actor, $term)->get('Employees', collect())
            ->map(fn ($result): int => $result->mobileTarget['id'])->all();
    }

    private function user(EmployeeRole $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'team_id' => $team?->id,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
