<?php

namespace Tests\Feature\DemoData;

use App\Actions\Teams\CreateTeam;
use App\DTOs\Teams\CreateTeamData;
use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamCreationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_team_through_validated_audited_action(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $team = app(CreateTeam::class)->handle(new CreateTeamData('Demo Operations', 'Validated team.', true), $owner);
        $this->assertSame('Demo Operations', $team->name);
        $this->assertTrue($team->status);
        $this->assertTrue(ActivityLog::query()->where('event', 'team.created')->where('subject_id', $team->id)->exists());
    }

    public function test_staff_cannot_create_team(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->expectException(AuthorizationException::class);
        app(CreateTeam::class)->handle(new CreateTeamData('Unauthorized Team'), $staff);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => strtolower($role->value).'-team-action@techpointzone.com']);
        Employee::factory()->create(['user_id' => $user->id, 'email' => $user->email, 'role' => $role, 'status' => true]);

        return $user;
    }
}
