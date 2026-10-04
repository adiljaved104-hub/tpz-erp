<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PeoplePermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeePermissionOverrideService
{
    public function __construct(
        private readonly EmployeePermissionCatalog $catalog,
        private readonly EmployeePermissionOverrideResolver $resolver,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function change(Employee $employee, string $permissionKey, ?EmployeePermissionEffect $effect, ?string $reason, User $actor): void
    {
        $reason = filled($reason) ? trim((string) $reason) : null;
        $this->validateChange($employee, $permissionKey, $reason, $actor);

        DB::transaction(function () use ($employee, $permissionKey, $effect, $reason, $actor): void {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $this->validateChange($lockedEmployee, $permissionKey, $reason, $actor);
            $this->applyLockedChange($lockedEmployee, $permissionKey, $effect, $reason, $actor);
        });

        $this->resolver->forgetEmployee((int) $employee->getKey());
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @param  array<string, EmployeePermissionEffect|null>  $changes
     */
    public function changeMany(array $employeeIds, array $changes, ?string $reason, User $actor): int
    {
        $employeeIds = collect($employeeIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values()->all();
        $reason = filled($reason) ? trim((string) $reason) : null;

        if ($employeeIds === [] || $changes === []) {
            throw ValidationException::withMessages(['employees' => 'Select at least one manageable employee and one access change.']);
        }

        $employees = Employee::query()->whereKey($employeeIds)->get();
        $this->validateBulkTargets($employees, $employeeIds, $changes, $reason, $actor);

        $changed = DB::transaction(function () use ($employeeIds, $changes, $reason, $actor): int {
            $lockedEmployees = Employee::query()->whereKey($employeeIds)->orderBy('id')->lockForUpdate()->get();
            $this->validateBulkTargets($lockedEmployees, $employeeIds, $changes, $reason, $actor);
            $changed = 0;

            foreach ($lockedEmployees as $employee) {
                foreach ($changes as $permissionKey => $effect) {
                    $changed += $this->applyLockedChange($employee, $permissionKey, $effect, $reason, $actor) ? 1 : 0;
                }
            }

            return $changed;
        });

        foreach ($employeeIds as $employeeId) {
            $this->resolver->forgetEmployee($employeeId);
        }

        return $changed;
    }

    private function validateChange(Employee $employee, string $permissionKey, ?string $reason, User $actor): void
    {
        if (! $actor->can('managePermissions', $employee)) {
            throw new AuthorizationException('This employee’s access is protected.');
        }

        $definition = $this->catalog->find($permissionKey);

        if ($definition === null) {
            throw ValidationException::withMessages(['permission' => 'This permission is not managed by this screen.']);
        }

        // This is the role-transition capability. Other employee permissions retain their rules.
        if ($permissionKey === PeoplePermission::EmployeeChangeRole->value
            && ! $actor->employee()->where('status', true)->where('role', EmployeeRole::Owner->value)->exists()) {
            throw new AuthorizationException('Only the Owner may change role-management access.');
        }

        if ($definition['financial'] && $actor->employee?->role !== EmployeeRole::Owner) {
            throw new AuthorizationException('Only the Owner may change financial access.');
        }

        if ($definition['financial'] && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for financial access changes.']);
        }
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<int, int>  $employeeIds
     * @param  array<string, EmployeePermissionEffect|null>  $changes
     */
    private function validateBulkTargets($employees, array $employeeIds, array $changes, ?string $reason, User $actor): void
    {
        if ($employees->count() !== count($employeeIds)) {
            throw ValidationException::withMessages(['employees' => 'One or more selected employees no longer exist. No access changes were saved.']);
        }

        foreach ($employees as $employee) {
            if (! $employee->status) {
                throw ValidationException::withMessages(['employees' => "{$employee->name} is inactive. Remove this employee from the bulk selection."]);
            }

            foreach ($changes as $permissionKey => $effect) {
                if (! ($effect instanceof EmployeePermissionEffect) && $effect !== null) {
                    throw ValidationException::withMessages(['permission' => 'One of the selected access settings is invalid.']);
                }

                $this->validateChange($employee, $permissionKey, $reason, $actor);
            }
        }
    }

    private function applyLockedChange(Employee $employee, string $permissionKey, ?EmployeePermissionEffect $effect, ?string $reason, User $actor): bool
    {
        $current = EmployeePermissionOverride::query()
            ->where('employee_id', $employee->getKey())
            ->where('permission_key', $permissionKey)
            ->lockForUpdate()
            ->first();
        $old = $current?->effect?->value ?? 'inherit';
        $new = $effect?->value ?? 'inherit';

        if ($old === $new) {
            return false;
        }

        if ($effect === null) {
            $current?->delete();
        } else {
            EmployeePermissionOverride::query()->upsert([[
                'employee_id' => $employee->getKey(),
                'permission_key' => $permissionKey,
                'effect' => $effect->value,
                'granted_by_user_id' => $actor->getKey(),
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['employee_id', 'permission_key'], ['effect', 'granted_by_user_id', 'reason', 'updated_at']);
        }

        $event = match ($new) {
            'allow' => 'employee_permission.allowed',
            'deny' => 'employee_permission.denied',
            default => 'employee_permission.inherited',
        };
        $this->activityLogger->log($event, $actor, $employee, [
            'employee_id' => $employee->getKey(),
            'permission_key' => $permissionKey,
            'old_override' => $old,
            'new_override' => $new,
            'actor_id' => $actor->getKey(),
            'reason_present' => $reason !== null,
        ]);

        return true;
    }
}
