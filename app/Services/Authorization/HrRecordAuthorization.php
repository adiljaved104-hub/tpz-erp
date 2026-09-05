<?php

namespace App\Services\Authorization;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\NoticeAudienceType;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class HrRecordAuthorization
{
    public function __construct(private readonly HrAuthorization $authorization) {}

    public function allows(User $user, HrPermission $permission): bool
    {
        return $this->authorization->allows($user, $permission);
    }

    public function scopeWarnings(Builder $query, User $user): Builder
    {
        if ($this->allows($user, HrPermission::WarningViewAll)) {
            return $query;
        }
        if ($this->allows($user, HrPermission::WarningViewTeam) && $user->employee?->team_id !== null) {
            return $query->whereHas('employee', fn (Builder $employees): Builder => $employees->where('team_id', $user->employee->team_id));
        }
        if ($this->allows($user, HrPermission::WarningViewOwn) && $user->employee !== null) {
            return $query->where('employee_id', $user->employee->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function canViewWarning(User $user, EmployeeWarning $warning): bool
    {
        return $this->scopeWarnings(EmployeeWarning::query()->whereKey($warning), $user)->exists();
    }

    public function canIssueWarning(User $user, Employee $employee): bool
    {
        if (! $this->allows($user, HrPermission::WarningIssue) || ! $employee->status) {
            return false;
        }

        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            || ($user->employee?->team_id !== null && $user->employee->team_id === $employee->team_id);
    }

    public function scopeNotices(Builder $query, User $user): Builder
    {
        if (in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            return $query;
        }
        if (($this->allows($user, HrPermission::NoticeManage) || $this->allows($user, HrPermission::NoticePublish))
            && $user->employee?->team_id !== null) {
            return $query->where(function (Builder $notices) use ($user): void {
                $notices->where('team_id', $user->employee->team_id)
                    ->orWhereHas('recipients.employee', fn (Builder $employees): Builder => $employees->where('team_id', $user->employee->team_id));
            });
        }
        if ($this->allows($user, HrPermission::NoticeView) && $user->employee !== null) {
            return $query->whereHas('recipients', fn (Builder $recipients): Builder => $recipients->where('employee_id', $user->employee->id));
        }

        return $query->whereRaw('1 = 0');
    }

    public function canViewNotice(User $user, HrNotice $notice): bool
    {
        return $this->scopeNotices(HrNotice::query()->whereKey($notice), $user)->exists();
    }

    public function canViewNoticeRecipientStatus(User $user): bool
    {
        return $this->allows($user, HrPermission::NoticeManage)
            || $this->allows($user, HrPermission::NoticePublish);
    }

    /** @param array<int, int> $employeeIds */
    public function canPublishNotice(User $user, NoticeAudienceType $audience, ?int $teamId, array $employeeIds): bool
    {
        if (! $this->allows($user, HrPermission::NoticePublish)) {
            return false;
        }
        if (in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            return true;
        }
        $ownTeamId = $user->employee?->team_id;
        if ($ownTeamId === null || $audience === NoticeAudienceType::All) {
            return false;
        }
        if ($audience === NoticeAudienceType::Team) {
            return $teamId === $ownTeamId;
        }

        return Employee::query()->whereKey($employeeIds)->where('team_id', '<>', $ownTeamId)->doesntExist();
    }
}
