<?php

namespace App\Actions\Employees;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Exceptions\LastOwnerException;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ChangeEmployeeRole
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EmployeePermissionOverrideResolver $overrides,
    ) {}

    public function handle(Employee $employee, EmployeeRole $role, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $role, $actor): Employee {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $actor->can('changeRole', [$employee, $role])) {
                throw new AuthorizationException;
            }

            if ($employee->role === EmployeeRole::Owner && $role !== EmployeeRole::Owner) {
                $this->guardOtherActiveOwner($employee);
            }

            $oldRole = $employee->role;
            $employee->forceFill(['role' => $role])->save();

            if (($oldRole === EmployeeRole::Technician) !== ($role === EmployeeRole::Technician)) {
                $this->clearAllowOverrides($employee, $actor, $oldRole, $role);
                $this->overrides->forgetEmployee((int) $employee->getKey());
            }

            $this->activity->log('employee.role_changed', $actor, $employee, [
                'from_role' => $oldRole->value,
                'to_role' => $role->value,
            ]);

            return $employee;
        });
    }

    private function clearAllowOverrides(Employee $employee, User $actor, EmployeeRole $oldRole, EmployeeRole $newRole): void
    {
        $overrides = EmployeePermissionOverride::query()
            ->where('employee_id', $employee->getKey())
            ->where('effect', EmployeePermissionEffect::Allow->value)
            ->lockForUpdate()
            ->get();

        if ($overrides->isEmpty()) {
            return;
        }

        $this->activity->log('employee_permission.technician_transition_pruned', $actor, $employee, [
            'from_role' => $oldRole->value,
            'to_role' => $newRole->value,
            'permission_keys' => $overrides->pluck('permission_key')->values()->all(),
        ]);

        EmployeePermissionOverride::query()->whereKey($overrides->modelKeys())->delete();
    }

    private function guardOtherActiveOwner(Employee $employee): void
    {
        if (! Employee::query()->whereKeyNot($employee->getKey())->where('role', EmployeeRole::Owner->value)->where('status', true)->whereNotNull('user_id')->lockForUpdate()->exists()) {
            throw new LastOwnerException('The last active linked Owner cannot be demoted.');
        }
    }
}
