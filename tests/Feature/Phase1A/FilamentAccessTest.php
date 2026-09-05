<?php

namespace Tests\Feature\Phase1A;

use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_user_one_has_temporary_unlinked_access(): void
    {
        $ownerTarget = User::factory()->create(['id' => 1]);
        $other = User::factory()->create();

        $this->assertTrue(app(EmployeeAccessService::class)->canAccessPanel($ownerTarget));
        $this->assertFalse(app(EmployeeAccessService::class)->canAccessPanel($other));
    }

    public function test_bootstrap_completion_log_permanently_closes_unlinked_exception(): void
    {
        $user = User::factory()->create(['id' => 1]);
        ActivityLog::query()->create([
            'event' => 'owner.bootstrap.completed',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
        ]);

        $this->assertFalse(app(EmployeeAccessService::class)->canAccessPanel($user));
    }

    public function test_linked_employee_must_be_active(): void
    {
        $user = User::factory()->create(['email' => 'active-panel-user@techpointzone.com']);
        $employee = Employee::factory()->for($user)->role(EmployeeRole::Staff)->create(['email' => $user->email]);

        $this->assertTrue(app(EmployeeAccessService::class)->canAccessPanel($user));
        $employee->update(['status' => false]);
        $this->assertFalse(app(EmployeeAccessService::class)->canAccessPanel($user->refresh()));
    }
}
