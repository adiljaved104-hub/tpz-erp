<?php

namespace App\Actions\Employees;

use App\DTOs\Employees\CreateEmployeeData;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateEmployee
{
    public function __construct(
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(CreateEmployeeData $data, User $actor): Employee
    {
        if (! $actor->can('create', Employee::class)) {
            throw new AuthorizationException;
        }

        return $this->create($data, $actor);
    }

    public function create(CreateEmployeeData $data, ?User $actor = null, ?string $reservedReference = null): Employee
    {
        $validated = Validator::make([
            'user_id' => $data->userId,
            'name' => $data->name,
            'email' => $data->email,
            'designation' => $data->designation,
            'role' => $data->role->value,
            'status' => $data->status,
            'phone' => $data->phone,
            'team_id' => $data->teamId,
            'joining_date' => $data->joiningDate?->toDateString(),
        ], [
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:employees,user_id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:employees,email'],
            'designation' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(EmployeeRole::class)],
            'status' => ['required', 'boolean'],
            'phone' => ['nullable', 'string', 'max:255'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'joining_date' => ['nullable', 'date'],
        ])->validate();

        $user = User::query()->findOrFail($data->userId);

        Validator::make(
            ['employee_email' => $validated['email'], 'user_email' => $user->email],
            ['employee_email' => ['same:user_email']],
            ['employee_email.same' => 'The Employee email must match the linked User email.'],
        )->validate();

        $reservedReference ??= $this->references->nextEmployeeReference();

        return DB::transaction(function () use ($validated, $data, $actor, $reservedReference): Employee {
            $employee = Employee::query()->create([
                ...$validated,
                'employee_id' => $reservedReference,
                'role' => $data->role,
            ]);

            $this->activity->log('employee.created', $actor, $employee, [
                'linked_user_id' => $employee->user_id,
                'role' => $employee->role->value,
                'active' => $employee->status,
            ]);

            return $employee;
        });
    }
}
