<?php

namespace Tests\Feature\Phase1A;

use App\Actions\Employees\ChangeEmployeeRole;
use App\Actions\Employees\SetEmployeeStatus;
use App\Actions\Employees\UnlinkUserFromEmployee;
use App\Enums\EmployeeRole;
use App\Exceptions\LastOwnerException;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LastOwnerProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_owner_cannot_be_demoted(): void
    {
        [$user, $employee] = $this->owner();
        $this->expectException(LastOwnerException::class);
        app(ChangeEmployeeRole::class)->handle($employee, EmployeeRole::Admin, $user);
    }

    public function test_last_owner_cannot_be_deactivated(): void
    {
        [$user, $employee] = $this->owner();
        $this->expectException(LastOwnerException::class);
        app(SetEmployeeStatus::class)->handle($employee, false, $user);
    }

    public function test_last_owner_cannot_be_unlinked(): void
    {
        [$user, $employee] = $this->owner();
        $this->expectException(LastOwnerException::class);
        app(UnlinkUserFromEmployee::class)->handle($employee, $user);
    }

    /** @return array{User, Employee} */
    private function owner(): array
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return [$user->refresh(), $employee];
    }
}
