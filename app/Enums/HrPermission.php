<?php

namespace App\Enums;

enum HrPermission: string
{
    case AttendanceViewOwn = 'attendance.view_own';
    case AttendanceViewTeam = 'attendance.view_team';
    case AttendanceViewAll = 'attendance.view_all';
    case AttendanceCorrect = 'attendance.correct';
    case AttendanceManagePolicy = 'attendance.manage_policy';
    case BiometricManage = 'biometric.manage';
    case LeaveViewOwn = 'leave.view_own';
    case LeaveRequest = 'leave.request';
    case LeaveViewTeam = 'leave.view_team';
    case LeaveViewAll = 'leave.view_all';
    case LeaveApprove = 'leave.approve';
    case LeaveRecordOnBehalf = 'leave.record_on_behalf';
    case LeaveManagePolicy = 'leave.manage_policy';
    case LeaveHistoricalView = 'leave.historical.view';
    case LeaveHistoricalRecord = 'leave.historical.record';
    case LeaveHistoricalImport = 'leave.historical.import';
    case LeaveHistoricalAdjust = 'leave.historical.adjust';
    case LeaveJoiningDateCorrect = 'leave.joining_date.correct';
    case WorkScheduleManage = 'work_schedule.manage';
    case WarningViewOwn = 'warning.view_own';
    case WarningViewTeam = 'warning.view_team';
    case WarningViewAll = 'warning.view_all';
    case WarningIssue = 'warning.issue';
    case WarningManage = 'warning.manage';
    case NoticeView = 'notice.view';
    case NoticePublish = 'notice.publish';
    case NoticeManage = 'notice.manage';
    case AnnouncementView = 'announcement.view';
    case AnnouncementPublish = 'announcement.publish';
    case AnnouncementManage = 'announcement.manage';
}
