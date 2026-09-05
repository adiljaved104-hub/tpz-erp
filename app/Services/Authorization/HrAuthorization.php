<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\User;

class HrAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, HrPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => in_array($permission, [
                HrPermission::AttendanceViewOwn, HrPermission::AttendanceViewTeam,
                HrPermission::LeaveViewOwn, HrPermission::LeaveRequest,
                HrPermission::LeaveViewTeam,
                HrPermission::WarningViewOwn, HrPermission::NoticeView,
            ], true),
            EmployeeRole::Staff => in_array($permission, [
                HrPermission::AttendanceViewOwn, HrPermission::LeaveViewOwn, HrPermission::LeaveRequest,
                HrPermission::WarningViewOwn, HrPermission::NoticeView,
            ], true),
            default => false,
        };
    }

    public function allows(User $user, HrPermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }
}
