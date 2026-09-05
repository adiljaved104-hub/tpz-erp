<?php

namespace Tests\Feature\Phase1A;

use App\Actions\Users\CreateUserAndEmployee;
use App\DTOs\Employees\CreateEmployeeData;
use App\DTOs\Users\CreateUserAccountData;
use App\DTOs\Users\CreateUserAndEmployeeData;
use App\Enums\EmployeeRole;
use App\Filament\Resources\Employees\Pages\CreateEmployee as CreateEmployeePage;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeLoginCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_accepts_backed_string_and_hydrated_enum_roles(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $cases = [
            ['admin', EmployeeRole::Admin, 'admin@example.com'],
            [EmployeeRole::Manager, EmployeeRole::Manager, 'manager@example.com'],
            ['staff', EmployeeRole::Staff, 'staff@example.com'],
        ];

        foreach ($cases as [$submittedRole, $expectedRole, $email]) {
            $usersBefore = User::query()->count();
            $employeesBefore = Employee::query()->count();

            Livewire::test(CreateEmployeePage::class)
                ->fillForm([
                    'name' => $expectedRole->getLabel().' Employee',
                    'email' => $email,
                    'password' => 'ValidPassword123!',
                    'password_confirmation' => 'ValidPassword123!',
                    'designation' => 'Operations',
                    'role' => $submittedRole,
                    'status' => true,
                ])
                ->call('create')
                ->assertHasNoErrors();

            $user = User::query()->where('email', $email)->firstOrFail();
            $employee = Employee::query()->where('email', $email)->firstOrFail();

            $this->assertSame($usersBefore + 1, User::query()->count());
            $this->assertSame($employeesBefore + 1, Employee::query()->count());
            $this->assertSame($user->id, $employee->user_id);
            $this->assertSame($expectedRole, $employee->role);
            $this->assertTrue(Hash::check('ValidPassword123!', $user->password));
            $this->assertNull($employee->getRawOriginal('password'));
            $this->assertSame(1, User::query()->where('email', $email)->count());
            $this->assertSame(1, Employee::query()->where('email', $email)->count());
            $this->assertSame(1, ActivityLog::query()->where('event', 'user.created')->where('subject_id', $user->id)->count());
            $this->assertSame(1, ActivityLog::query()->where('event', 'employee.created')->where('subject_id', $employee->id)->count());
            $this->assertSame(1, ActivityLog::query()->where('event', 'user_employee.linked')->where('subject_id', $employee->id)->count());
        }

        $logs = ActivityLog::query()
            ->whereIn('event', ['user.created', 'employee.created', 'user_employee.linked'])
            ->get(['event', 'properties', 'description'])
            ->toJson();
        $this->assertStringNotContainsString('ValidPassword123!', $logs);
        $this->assertStringNotContainsString('password', strtolower($logs));
        $this->assertStringNotContainsString('$2y$', $logs);
    }

    public function test_failure_after_user_insert_rolls_back_both_records(): void
    {
        $owner = $this->owner();
        $usersBefore = User::query()->count();
        $employeesBefore = Employee::query()->count();

        try {
            app(CreateUserAndEmployee::class)->handle(new CreateUserAndEmployeeData(
                new CreateUserAccountData('Invalid Employee', 'invalid@example.com', 'ValidPassword123!'),
                new CreateEmployeeData(
                    userId: 0,
                    name: 'Invalid Employee',
                    email: 'invalid@example.com',
                    designation: 'Operations',
                    role: EmployeeRole::Staff,
                    teamId: 999999,
                ),
            ), $owner);
            $this->fail('Invalid Employee data should fail the transaction.');
        } catch (ValidationException) {
            $this->assertSame($usersBefore, User::query()->count());
            $this->assertSame($employeesBefore, Employee::query()->count());
            $this->assertFalse(User::query()->where('email', 'invalid@example.com')->exists());
            $this->assertFalse(Employee::query()->where('email', 'invalid@example.com')->exists());
        }
    }

    public function test_duplicate_email_and_invalid_role_are_reported_without_partial_records(): void
    {
        $owner = $this->owner();
        User::factory()->create(['email' => 'duplicate@example.com']);
        $this->actingAs($owner);
        $usersBefore = User::query()->count();
        $employeesBefore = Employee::query()->count();

        Livewire::test(CreateEmployeePage::class)
            ->fillForm($this->formData('duplicate@example.com', 'staff'))
            ->call('create')
            ->assertHasFormErrors(['email']);

        Livewire::test(CreateEmployeePage::class)
            ->fillForm($this->formData('invalid-role@example.com', 'superadmin'))
            ->call('create')
            ->assertHasFormErrors(['role']);

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($employeesBefore, Employee::query()->count());
        $this->assertFalse(User::query()->where('email', 'invalid-role@example.com')->exists());
    }

    public function test_user_validation_failure_creates_no_partial_records(): void
    {
        $owner = $this->owner();
        $usersBefore = User::query()->count();
        $employeesBefore = Employee::query()->count();

        try {
            app(CreateUserAndEmployee::class)->handle(new CreateUserAndEmployeeData(
                new CreateUserAccountData('Invalid User', 'not-an-email', 'ValidPassword123!'),
                new CreateEmployeeData(0, 'Invalid User', 'not-an-email', 'Operations', EmployeeRole::Staff),
            ), $owner);
            $this->fail('Invalid User data should fail validation.');
        } catch (ValidationException) {
            $this->assertSame($usersBefore, User::query()->count());
            $this->assertSame($employeesBefore, Employee::query()->count());
        }
    }

    public function test_owner_role_is_rejected_and_unauthorized_staff_cannot_access_page(): void
    {
        $owner = $this->owner();
        $usersBefore = User::query()->count();
        $employeesBefore = Employee::query()->count();

        try {
            app(CreateUserAndEmployee::class)->handle(new CreateUserAndEmployeeData(
                new CreateUserAccountData('Second Owner', 'owner2@example.com', 'ValidPassword123!'),
                new CreateEmployeeData(0, 'Second Owner', 'owner2@example.com', 'Owner', EmployeeRole::Owner),
            ), $owner);
            $this->fail('The combined workflow must not create another Owner.');
        } catch (ValidationException) {
            $this->assertSame($usersBefore, User::query()->count());
            $this->assertSame($employeesBefore, Employee::query()->count());
        }

        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $this->actingAs($staff)->get(CreateEmployeePage::getUrl())->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function formData(string $email, EmployeeRole|string $role): array
    {
        return [
            'name' => 'Employee',
            'email' => $email,
            'password' => 'ValidPassword123!',
            'password_confirmation' => 'ValidPassword123!',
            'designation' => 'Operations',
            'role' => $role,
            'status' => true,
        ];
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
