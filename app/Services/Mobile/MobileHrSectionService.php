<?php

namespace App\Services\Mobile;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Authorization\HrRecordAuthorization;

class MobileHrSectionService
{
    public function sections(User $user): array
    {
        $records = app(HrRecordAuthorization::class);
        $hr = app(HrAuthorization::class);

        return array_values(array_filter([
            $user->can('viewAny', Employee::class) || $user->can('view', $user->employee)
                ? ['key' => 'employees', 'title' => 'Employees'] : null,
            $user->can('viewAny', Team::class) ? ['key' => 'teams', 'title' => 'Teams'] : null,
            $records->allows($user, HrPermission::NoticeView) || $records->allows($user, HrPermission::NoticeManage)
                || $records->allows($user, HrPermission::NoticePublish)
                || $records->allows($user, HrPermission::AnnouncementView) || $records->allows($user, HrPermission::AnnouncementManage)
                || $records->allows($user, HrPermission::AnnouncementPublish)
                ? ['key' => 'notices', 'title' => 'Official Notices & Announcements'] : null,
            $records->allows($user, HrPermission::WarningViewOwn) || $records->allows($user, HrPermission::WarningViewTeam)
                || $records->allows($user, HrPermission::WarningViewAll)
                ? ['key' => 'warnings', 'title' => 'Employee Warnings'] : null,
            $hr->allows($user, HrPermission::LeaveViewOwn)
                || $hr->allows($user, HrPermission::LeaveViewTeam)
                || $hr->allows($user, HrPermission::LeaveViewAll)
                ? ['key' => 'leave-requests', 'title' => 'Leave Requests'] : null,
        ]));
    }
}
