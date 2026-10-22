<?php

namespace App\Services\Hr;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\HistoricalLeaveRecord;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use Illuminate\Database\Eloquent\Builder;

class HistoricalLeaveAuthorization
{
    public function __construct(private readonly HrAuthorization $authorization) {}

    public function canManagePolicy(User $user): bool
    {
        return $this->authorization->allows($user, HrPermission::LeaveManagePolicy);
    }

    public function employeeQuery(User $user, HrPermission $requiredPermission, bool $excludeSelf = false, bool $requireVerifiedJoiningDate = true): Builder
    {
        $query = Employee::query();
        if ($requireVerifiedJoiningDate) {
            $query->whereNotNull('joining_date')->whereNotNull('joining_date_verified_at');
        }
        if (! $this->authorization->allows($user, $requiredPermission) || $user->employee === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($excludeSelf) {
            $query->whereKeyNot($user->employee->id);
        }
        if (in_array($user->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            return $query;
        }
        if ($this->authorization->allows($user, HrPermission::LeaveViewAll)) {
            return $query;
        }
        if ($this->authorization->allows($user, HrPermission::LeaveViewTeam) && $user->employee->team_id !== null) {
            return $query->where('team_id', $user->employee->team_id);
        }
        if (! $excludeSelf && $this->authorization->allows($user, HrPermission::LeaveViewOwn)) {
            return $query->whereKey($user->employee->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function canRecordEmployee(User $user, Employee $employee, HrPermission $permission): bool
    {
        return $this->employeeQuery($user, $permission, excludeSelf: true)->whereKey($employee->id)->exists();
    }

    public function canViewEmployee(User $user, Employee $employee): bool
    {
        return $this->employeeQuery($user, HrPermission::LeaveHistoricalView)->whereKey($employee->id)->exists();
    }

    public function visibleRecords(User $user): Builder
    {
        $employeeQuery = $this->employeeQuery($user, HrPermission::LeaveHistoricalView);

        return HistoricalLeaveRecord::query()->whereIn('employee_id', $employeeQuery->select('id'));
    }
}
