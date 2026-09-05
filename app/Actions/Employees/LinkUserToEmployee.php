<?php

namespace App\Actions\Employees;

use App\DTOs\Employees\LinkUserToEmployeeData;
use App\Exceptions\EmployeeLinkConflictException;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class LinkUserToEmployee
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(LinkUserToEmployeeData $data, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor): Employee {
            $employee = Employee::query()->lockForUpdate()->findOrFail($data->employeeId);
            $user = User::query()->lockForUpdate()->findOrFail($data->userId);

            if (! $actor->can('linkUser', $employee)) {
                throw new AuthorizationException;
            }

            if ($employee->user_id !== null || Employee::query()->where('user_id', $user->getKey())->exists()) {
                throw new EmployeeLinkConflictException('The Employee or User is already linked.');
            }

            if (mb_strtolower(trim($employee->email)) !== mb_strtolower(trim($user->email))) {
                throw new EmployeeLinkConflictException('Employee and User emails must match.');
            }

            $employee->forceFill(['user_id' => $user->getKey()])->save();
            $this->activity->log('employee.user_linked', $actor, $employee, ['linked_user_id' => $user->getKey()]);

            return $employee;
        });
    }
}
