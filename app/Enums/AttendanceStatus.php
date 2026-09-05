<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case HalfDay = 'half_day';
    case ApprovedLeave = 'approved_leave';
    case UnpaidLeave = 'unpaid_leave';
    case PublicHoliday = 'public_holiday';
    case WeekendOff = 'weekend_off';
    case CompensatoryOff = 'compensatory_off';
}
