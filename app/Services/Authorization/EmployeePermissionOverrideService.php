<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
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
        if (! $actor->can('managePermissions', $employee)) {
            throw new AuthorizationException;
        }

        $definition = $this->catalog->find($permissionKey);

        if ($definition === null) {
            throw ValidationException::withMessages(['permission' => 'This permission is not managed by this screen.']);
        }

        if ($definition['financial'] && $actor->employee?->role !== EmployeeRole::Owner) {
            throw new AuthorizationException('Only the Owner may change financial access.');
        }

        $reason = filled($reason) ? trim((string) $reason) : null;

        if ($definition['financial'] && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for financial access changes.']);
        }

        DB::transaction(function () use ($employee, $permissionKey, $effect, $reason, $actor): void {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $actor->can('managePermissions', $lockedEmployee)) {
                throw new AuthorizationException;
            }

            $current = EmployeePermissionOverride::query()
                ->where('employee_id', $lockedEmployee->getKey())
                ->where('permission_key', $permissionKey)
                ->lockForUpdate()
                ->first();
            $old = $current?->effect?->value ?? 'inherit';
            $new = $effect?->value ?? 'inherit';

            if ($old === $new) {
                return;
            }

            if ($effect === null) {
                $current?->delete();
            } else {
                EmployeePermissionOverride::query()->upsert([[
                    'employee_id' => $lockedEmployee->getKey(),
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
            $this->activityLogger->log($event, $actor, $lockedEmployee, [
                'employee_id' => $lockedEmployee->getKey(),
                'permission_key' => $permissionKey,
                'old_override' => $old,
                'new_override' => $new,
                'actor_id' => $actor->getKey(),
                'reason_present' => $reason !== null,
            ]);
        });

        $this->resolver->forgetEmployee((int) $employee->getKey());
    }
}
