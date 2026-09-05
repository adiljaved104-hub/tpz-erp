<?php

namespace App\Actions\Users;

use App\Actions\Employees\CreateEmployee;
use App\DTOs\Employees\CreateEmployeeData;
use App\DTOs\Users\CreateUserAndEmployeeData;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateUserAndEmployee
{
    public function __construct(
        private readonly CreateEmployee $employees,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(CreateUserAndEmployeeData $data, User $actor): Employee
    {
        if (! $actor->can('create', Employee::class)) {
            throw new AuthorizationException;
        }

        Validator::make([
            'role' => $data->employee->role->value,
        ], [
            'role' => ['required', 'not_in:'.EmployeeRole::Owner->value],
        ], [
            'role.not_in' => 'Owner accounts cannot be created through this workflow.',
        ])->validate();

        $userData = Validator::make([
            'name' => $data->user->name,
            'email' => $data->user->email,
            'password' => $data->user->password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:employees,email'],
            'password' => ['required', Password::defaults()],
        ])->validate();

        $reservedReference = $this->references->nextEmployeeReference();

        return DB::transaction(function () use ($data, $userData, $actor, $reservedReference): Employee {
            $user = User::query()->create($userData);
            $this->activity->log('user.created', $actor, $user, [
                'created_fields' => ['name', 'email'],
            ]);
            $employeeData = new CreateEmployeeData(
                userId: $user->getKey(),
                name: $data->employee->name,
                email: $user->email,
                designation: $data->employee->designation,
                role: $data->employee->role,
                status: $data->employee->status,
                phone: $data->employee->phone,
                teamId: $data->employee->teamId,
                joiningDate: $data->employee->joiningDate,
            );

            $employee = $this->employees->create($employeeData, $actor, $reservedReference);
            $this->activity->log('user_employee.linked', $actor, $employee, [
                'linked_user_id' => $user->getKey(),
                'role' => $employee->role->value,
            ]);

            return $employee;
        });
    }
}
