<?php

namespace Tests\Feature\Phase1A;

use App\Actions\Employees\BootstrapInitialOwner;
use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_user_one_is_the_only_bootstrap_target(): void
    {
        User::factory()->create([
            'id' => 1,
            'name' => BootstrapInitialOwner::OWNER_NAME,
            'email' => BootstrapInitialOwner::OWNER_EMAIL,
        ]);

        $employee = app(BootstrapInitialOwner::class)->handle();

        $this->assertSame(1, $employee->user_id);
        $this->assertSame(EmployeeRole::Owner, $employee->role);
        $this->assertSame('Owner / Managing Director', $employee->designation);
        $this->assertNull($employee->phone);
        $this->assertNull($employee->team_id);
        $this->assertNull($employee->joining_date);
        $this->assertNull($employee->getRawOriginal('password'));
        $this->assertDatabaseCount('employees', 1);
        $this->assertTrue(ActivityLog::query()->where('event', 'owner.bootstrap.completed')->exists());

        $sameEmployee = app(BootstrapInitialOwner::class)->handle();
        $this->assertTrue($sameEmployee->is($employee));
        $this->assertDatabaseCount('employees', 1);
    }
}
