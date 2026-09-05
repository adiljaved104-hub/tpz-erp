<?php

namespace Tests\Feature\Phase1A;

use App\Actions\Employees\CreateEmployee;
use App\Actions\Users\CreateUserAndEmployee;
use App\DTOs\Employees\CreateEmployeeData;
use App\DTOs\Users\CreateUserAccountData;
use App\DTOs\Users\CreateUserAndEmployeeData;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\ReferenceSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;
use Tests\TestCase;

class EmployeeCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_employee_links_an_existing_user_and_never_gets_a_password(): void
    {
        $actor = $this->owner();
        $user = User::factory()->create(['email' => 'employee@example.com']);

        $employee = app(CreateEmployee::class)->handle(new CreateEmployeeData(
            userId: $user->id,
            name: 'Employee One',
            email: $user->email,
            designation: 'Sales',
        ), $actor);

        $this->assertSame($user->id, $employee->user_id);
        $this->assertNull($employee->getRawOriginal('password'));
        $this->assertMatchesRegularExpression('/^TPZ-\d{4,}$/', $employee->employee_id);
    }

    public function test_controlled_workflow_creates_user_and_employee_atomically_without_copying_password(): void
    {
        $actor = $this->owner();

        $employee = app(CreateUserAndEmployee::class)->handle(new CreateUserAndEmployeeData(
            new CreateUserAccountData('New User', 'new@example.com', 'ValidPassword123!'),
            new CreateEmployeeData(0, 'New User', 'new@example.com', 'Operations'),
        ), $actor);

        $this->assertTrue(Hash::check('ValidPassword123!', $employee->user->password));
        $this->assertNull($employee->getRawOriginal('password'));
    }

    public function test_employee_creation_dto_has_no_password_property(): void
    {
        $properties = collect((new ReflectionClass(CreateEmployeeData::class))->getProperties())->pluck('name');

        $this->assertFalse($properties->contains('password'));
    }

    public function test_reference_allocator_returns_unique_sequential_references(): void
    {
        $references = app(ReferenceSequenceService::class);

        $this->assertSame('TPZ-0001', $references->nextEmployeeReference());
        $this->assertSame('TPZ-0002', $references->nextEmployeeReference());
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->create(['email' => $user->email, 'role' => EmployeeRole::Owner]);

        return $user->refresh();
    }
}
