<?php

namespace App\Actions\Employees;

use App\Enums\EmployeeRole;
use App\Exceptions\LastOwnerException;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ChangeEmployeeRole
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(Employee $employee, EmployeeRole $role, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $role, $actor): Employee {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $actor->can('changeRole', $employee)) {
                throw new AuthorizationException;
            }

            if ($employee->role === EmployeeRole::Owner && $role !== EmployeeRole::Owner) {
                $this->guardOtherActiveOwner($employee);
            }

            $oldRole = $employee->role;
            $employee->forceFill(['role' => $role])->save();
            $this->activity->log('employee.role_changed', $actor, $employee, [
                'from_role' => $oldRole->value,
                'to_role' => $role->value,
            ]);

            return $employee;
        });
    }

    private function guardOtherActiveOwner(Employee $employee): void
    {
        if (! Employee::query()->whereKeyNot($employee->getKey())->where('role', EmployeeRole::Owner->value)->where('status', true)->whereNotNull('user_id')->lockForUpdate()->exists()) {
            throw new LastOwnerException('The last active linked Owner cannot be demoted.');
        }
    }
}
