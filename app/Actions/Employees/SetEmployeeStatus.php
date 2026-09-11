<?php

namespace App\Actions\Employees;

use App\Enums\EmployeeRole;
use App\Exceptions\LastOwnerException;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SetEmployeeStatus
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(Employee $employee, bool $active, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $active, $actor): Employee {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $actor->can('changeStatus', $employee)) {
                throw new AuthorizationException;
            }

            if (! $active && $employee->status && $employee->role === EmployeeRole::Owner) {
                $hasOtherOwner = Employee::query()->whereKeyNot($employee->getKey())->where('role', EmployeeRole::Owner->value)->where('status', true)->whereNotNull('user_id')->lockForUpdate()->exists();

                if (! $hasOtherOwner) {
                    throw new LastOwnerException('The last active linked Owner cannot be deactivated.');
                }
            }

            $oldStatus = $employee->status;
            $employee->forceFill(['status' => $active])->save();

            if (! $active && $employee->user_id !== null) {
                User::query()->find($employee->user_id)?->tokens()->delete();
            }

            $this->activity->log('employee.status_changed', $actor, $employee, [
                'from_active' => $oldStatus,
                'to_active' => $active,
            ]);

            return $employee;
        });
    }
}
