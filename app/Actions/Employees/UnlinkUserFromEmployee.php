<?php

namespace App\Actions\Employees;

use App\Enums\EmployeeRole;
use App\Exceptions\LastOwnerException;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UnlinkUserFromEmployee
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(Employee $employee, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $actor): Employee {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $actor->can('unlinkUser', $employee)) {
                throw new AuthorizationException;
            }

            $this->guardLastOwner($employee);
            $oldUserId = $employee->user_id;
            $employee->forceFill(['user_id' => null])->save();
            $this->activity->log('employee.user_unlinked', $actor, $employee, ['unlinked_user_id' => $oldUserId]);

            return $employee;
        });
    }

    private function guardLastOwner(Employee $employee): void
    {
        if ($employee->role !== EmployeeRole::Owner || ! $employee->status || $employee->user_id === null) {
            return;
        }

        $otherActiveOwners = Employee::query()
            ->whereKeyNot($employee->getKey())
            ->where('role', EmployeeRole::Owner->value)
            ->where('status', true)
            ->whereNotNull('user_id')
            ->lockForUpdate()
            ->exists();

        if (! $otherActiveOwners) {
            throw new LastOwnerException('The last active linked Owner cannot be unlinked.');
        }
    }
}
