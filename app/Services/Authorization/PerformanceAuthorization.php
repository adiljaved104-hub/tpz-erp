<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\PerformancePermission;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PerformanceAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, PerformancePermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => in_array($permission, [PerformancePermission::ViewOwn, PerformancePermission::ViewTeam], true),
            EmployeeRole::Staff => $permission === PerformancePermission::ViewOwn,
            default => false,
        };
    }

    public function allows(User $user, PerformancePermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function canViewEmployee(User $user, Employee $employee): bool
    {
        if ($this->allows($user, PerformancePermission::ViewAll)) {
            return true;
        }
        if ($user->employee?->is($employee)) {
            return $this->allows($user, PerformancePermission::ViewOwn);
        }

        return $this->allows($user, PerformancePermission::ViewTeam)
            && $user->employee?->team_id !== null
            && $user->employee->team_id === $employee->team_id;
    }

    public function employeeQuery(User $user): Builder
    {
        $query = Employee::query()->where('status', true);
        if ($this->allows($user, PerformancePermission::ViewAll)) {
            return $query;
        }
        if ($this->allows($user, PerformancePermission::ViewTeam) && $user->employee?->team_id !== null) {
            return $query->where('team_id', $user->employee->team_id);
        }
        if ($this->allows($user, PerformancePermission::ViewOwn) && $user->employee !== null) {
            return $query->whereKey($user->employee->id);
        }

        return $query->whereRaw('1 = 0');
    }
}
