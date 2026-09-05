<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\TaskPermission;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class TaskAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, TaskPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => $permission !== TaskPermission::ManageAll,
            EmployeeRole::Staff => in_array($permission, [TaskPermission::View, TaskPermission::ChangeStatus, TaskPermission::Complete], true),
            default => false,
        };
    }

    public function allows(User $user, TaskPermission $permission, ?Task $task = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
        if (! $allowed) {
            return false;
        }
        if ($task === null || $permission === TaskPermission::Create) {
            return true;
        }
        if ($this->effective($user, TaskPermission::ManageAll)) {
            return true;
        }

        $own = $task->assignments()->where('employee_id', $user->employee->id)->whereNotIn('status', ['cancelled', 'removed'])->exists()
            || ($task->assignments()->doesntExist() && $task->assigned_employee_id === $user->employee->id);
        $created = $task->created_by_user_id === $user->id;
        $team = $this->effective($user, TaskPermission::ViewTeam) && $user->employee->team_id !== null
            && ($task->assigned_team_id === $user->employee->team_id
                || $task->assignments()->where('team_id_at_assignment', $user->employee->team_id)->whereNotIn('status', ['cancelled', 'removed'])->exists()
                || $task->assignedEmployee?->team_id === $user->employee->team_id);

        if ($permission === TaskPermission::View) {
            return $own || $team || $created;
        }
        if ($permission === TaskPermission::Assign) {
            return $team || $created;
        }

        if (in_array($permission, [TaskPermission::ChangeStatus, TaskPermission::Complete], true)) {
            return $own;
        }

        return $own || $team;
    }

    public function authorize(User $user, TaskPermission $permission, ?Task $task = null): void
    {
        if (! $this->allows($user, $permission, $task)) {
            throw new AuthorizationException;
        }
    }

    public function scopeQuery(Builder $query, User $user): Builder
    {
        if (! $this->allows($user, TaskPermission::View)) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->effective($user, TaskPermission::ManageAll)) {
            return $query;
        }
        $employee = $user->employee;

        return $query->where(function (Builder $scope) use ($user, $employee): void {
            $scope->whereHas('assignments', fn (Builder $assignment) => $assignment->where('employee_id', $employee->id)->whereNotIn('status', ['cancelled', 'removed']))
                ->orWhere('assigned_employee_id', $employee->id)
                ->orWhere('created_by_user_id', $user->id);
            if ($this->effective($user, TaskPermission::ViewTeam) && $employee->team_id !== null) {
                $scope->orWhere('assigned_team_id', $employee->team_id)
                    ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('team_id_at_assignment', $employee->team_id)->whereNotIn('status', ['cancelled', 'removed']))
                    ->orWhereHas('assignedEmployee', fn (Builder $q) => $q->where('team_id', $employee->team_id));
            }
        });
    }

    public function canReopen(User $user, Task $task): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && $this->allows($user, TaskPermission::ChangeStatus, $task);
    }

    public function isAssignee(User $user, Task $task): bool
    {
        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return false;
        }

        return $task->assignments()->where('employee_id', $employeeId)
            ->whereNotIn('status', ['cancelled', 'removed', 'completed'])->exists()
            || ($task->assignments()->doesntExist() && $task->assigned_employee_id === $employeeId);
    }

    public function assignmentFor(User $user, Task $task): ?TaskAssignment
    {
        return $task->assignments()->where('employee_id', $user->employee?->id)
            ->whereNotIn('status', ['cancelled', 'removed', 'completed'])->first();
    }

    public function canSupervise(User $user, Task $task): bool
    {
        return ! $this->isAssignee($user, $task)
            && $this->effective($user, TaskPermission::Complete)
            && ($this->effective($user, TaskPermission::ManageAll)
                || ($this->effective($user, TaskPermission::ViewTeam)
                    && $user->employee?->team_id !== null
                    && ($task->assignments()->where('team_id_at_assignment', $user->employee->team_id)
                        ->whereNotIn('status', ['cancelled', 'removed'])->exists()
                        || ($task->assignments()->doesntExist() && $task->assignedEmployee?->team_id === $user->employee->team_id))));
    }

    public function canAssignEmployee(User $user, Employee $employee): bool
    {
        if (! $this->effective($user, TaskPermission::Assign) || $employee->status !== true || $employee->user_id === null) {
            return false;
        }
        if ($this->effective($user, TaskPermission::ManageAll)) {
            return true;
        }

        return $user->employee?->team_id !== null && $employee->team_id === $user->employee->team_id;
    }

    public function canAssignTeam(User $user, Team $team): bool
    {
        if (! $this->effective($user, TaskPermission::Assign) || ! (bool) $team->status) {
            return false;
        }
        if ($this->effective($user, TaskPermission::ManageAll)) {
            return true;
        }

        return $user->employee?->team_id !== null && $team->id === $user->employee->team_id;
    }

    private function effective(User $user, TaskPermission $permission): bool
    {
        $default = $this->roleDefault($user, $permission);

        return $user->employee?->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);
    }
}
