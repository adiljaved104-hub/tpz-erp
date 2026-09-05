<?php

namespace App\Services\Hr;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use Illuminate\Database\Eloquent\Builder;

class HrScopeService
{
    public function __construct(private readonly HrAuthorization $authorization) {}

    public function attendanceQuery(User $user): Builder
    {
        return $this->scopeEmployeeRelation(EmployeeAttendance::query(), $user,
            HrPermission::AttendanceViewOwn, HrPermission::AttendanceViewTeam, HrPermission::AttendanceViewAll);
    }

    public function leaveQuery(User $user): Builder
    {
        return $this->scopeEmployeeRelation(LeaveRequest::query(), $user,
            HrPermission::LeaveViewOwn, HrPermission::LeaveViewTeam, HrPermission::LeaveViewAll);
    }

    public function canViewAttendance(User $user, Employee $employee): bool
    {
        return $this->canViewEmployee($user, $employee,
            HrPermission::AttendanceViewOwn, HrPermission::AttendanceViewTeam, HrPermission::AttendanceViewAll);
    }

    public function canViewLeave(User $user, Employee $employee): bool
    {
        return $this->canViewEmployee($user, $employee,
            HrPermission::LeaveViewOwn, HrPermission::LeaveViewTeam, HrPermission::LeaveViewAll);
    }

    public function canCorrect(User $user, Employee $employee): bool
    {
        return $this->authorization->allows($user, HrPermission::AttendanceCorrect)
            && $this->canViewAttendance($user, $employee);
    }

    public function canApprove(User $user, LeaveRequest $request): bool
    {
        if (! $this->authorization->allows($user, HrPermission::LeaveApprove)
            || $user->employee?->is($request->employee)) {
            return false;
        }

        if (in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            return true;
        }

        return $user->employee?->team_id !== null
            && $user->employee->team_id === $request->employee?->team_id;
    }

    public function canApproveEmployee(User $user, Employee $employee): bool
    {
        if (! $this->authorization->allows($user, HrPermission::LeaveApprove) || $user->employee?->is($employee)) {
            return false;
        }
        if (in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            return true;
        }

        return $user->employee?->team_id !== null && $user->employee->team_id === $employee->team_id;
    }

    public function employeeQuery(User $user, bool $attendance): Builder
    {
        $all = $attendance ? HrPermission::AttendanceViewAll : HrPermission::LeaveViewAll;
        $team = $attendance ? HrPermission::AttendanceViewTeam : HrPermission::LeaveViewTeam;
        $own = $attendance ? HrPermission::AttendanceViewOwn : HrPermission::LeaveViewOwn;
        $query = Employee::query()->where('status', true);

        if ($this->authorization->allows($user, $all)) {
            return $query;
        }
        if ($this->authorization->allows($user, $team) && $user->employee?->team_id !== null) {
            return $query->where('team_id', $user->employee->team_id);
        }
        if ($this->authorization->allows($user, $own) && $user->employee !== null) {
            return $query->whereKey($user->employee->id);
        }

        return $query->whereRaw('1 = 0');
    }

    private function canViewEmployee(User $user, Employee $employee, HrPermission $own, HrPermission $team, HrPermission $all): bool
    {
        if ($this->authorization->allows($user, $all)) {
            return true;
        }
        if ($this->authorization->allows($user, $team)
            && $user->employee?->team_id !== null
            && $user->employee->team_id === $employee->team_id) {
            return true;
        }

        return $this->authorization->allows($user, $own) && $user->employee?->is($employee);
    }

    private function scopeEmployeeRelation(Builder $query, User $user, HrPermission $own, HrPermission $team, HrPermission $all): Builder
    {
        if ($this->authorization->allows($user, $all)) {
            return $query;
        }
        if ($this->authorization->allows($user, $team) && $user->employee?->team_id !== null) {
            return $query->whereHas('employee', fn (Builder $employees): Builder => $employees->where('team_id', $user->employee->team_id));
        }
        if ($this->authorization->allows($user, $own) && $user->employee !== null) {
            return $query->where('employee_id', $user->employee->id);
        }

        return $query->whereRaw('1 = 0');
    }
}
