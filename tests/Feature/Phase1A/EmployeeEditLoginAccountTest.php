<?php

namespace Tests\Feature\Phase1A;

use App\Enums\EmployeeRole;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeEditLoginAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_login_is_labelled_and_employee_changes_save_without_relinking(): void
    {
        $this->actingAs($this->owner());
        $linkedUser = User::factory()->create([
            'name' => 'Admin Test',
            'email' => 'testadmin@gmail.com',
        ]);
        $employee = Employee::factory()->for($linkedUser)->role(EmployeeRole::Admin)->create([
            'name' => $linkedUser->name,
            'email' => $linkedUser->email,
        ]);
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $usersBefore = User::query()->count();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertSchemaComponentDoesNotExist('user_id')
            ->assertSchemaComponentExists('linked_login_account', 'form', fn (Placeholder $component): bool => $component->getContent() === "Admin Test \u{2014} testadmin@gmail.com")
            ->fillForm([
                'phone' => '+971500000001',
                'team_id' => $team->id,
                'designation' => 'Operations Manager',
                'role' => EmployeeRole::Manager,
                'joining_date' => '2026-08-01',
                'status' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee->refresh();

        $this->assertSame($linkedUser->id, $employee->user_id);
        $this->assertSame('+971500000001', $employee->phone);
        $this->assertSame($team->id, $employee->team_id);
        $this->assertSame('Operations Manager', $employee->designation);
        $this->assertSame(EmployeeRole::Manager, $employee->role);
        $this->assertSame('2026-08-01', $employee->joining_date?->toDateString());
        $this->assertTrue($employee->status);
        $this->assertSame($usersBefore, User::query()->count());
    }

    public function test_general_employee_update_rejects_login_account_tampering(): void
    {
        $this->actingAs($this->owner());
        $currentUser = User::factory()->create();
        $employee = Employee::factory()->for($currentUser)->role(EmployeeRole::Admin)->create([
            'email' => $currentUser->email,
        ]);
        $otherUser = User::factory()->create();
        Employee::factory()->for($otherUser)->create(['email' => $otherUser->email]);
        $employeesBefore = Employee::query()->count();

        $method = new ReflectionMethod(EditEmployee::class, 'handleRecordUpdate');
        $method->setAccessible(true);

        try {
            $method->invoke(new EditEmployee, $employee, [
                'user_id' => $otherUser->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'team_id' => $employee->team_id,
                'designation' => $employee->designation,
                'role' => $employee->role,
                'status' => $employee->status,
                'joining_date' => $employee->joining_date,
            ]);

            $this->fail('The general Employee edit workflow must reject Login Account changes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user_id', $exception->errors());
        }

        $this->assertSame($currentUser->id, $employee->fresh()->user_id);
        $this->assertSame(1, Employee::query()->where('user_id', $otherUser->id)->count());
        $this->assertSame($employeesBefore, Employee::query()->count());
    }

    public function test_unauthorized_employee_cannot_open_the_edit_page(): void
    {
        $staffUser = User::factory()->create();
        Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create([
            'email' => $staffUser->email,
        ]);
        $targetUser = User::factory()->create();
        $target = Employee::factory()->for($targetUser)->role(EmployeeRole::Admin)->create([
            'email' => $targetUser->email,
        ]);

        $this->actingAs($staffUser)
            ->get(EmployeeResource::getUrl('edit', ['record' => $target]))
            ->assertForbidden();
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
