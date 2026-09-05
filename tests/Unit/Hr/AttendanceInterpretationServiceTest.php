<?php

namespace Tests\Unit\Hr;

use App\DTOs\Attendance\AttendanceDayContext;
use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceInterpretationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttendanceInterpretationServiceTest extends TestCase
{
    #[DataProvider('contexts')]
    public function test_interpretation_precedence_preserves_approved_non_absence_days(AttendanceDayContext $context, AttendanceStatus $expected): void
    {
        self::assertSame($expected, (new AttendanceInterpretationService)->interpret($context));
    }

    public static function contexts(): array
    {
        return [
            'manual correction wins' => [new AttendanceDayContext(AttendanceStatus::Present, true, true, true, AttendanceStatus::ApprovedLeave, false), AttendanceStatus::Present],
            'public holiday is not absent' => [new AttendanceDayContext(null, true, false, false, null, false), AttendanceStatus::PublicHoliday],
            'scheduled weekend is not absent' => [new AttendanceDayContext(null, false, true, false, null, false), AttendanceStatus::WeekendOff],
            'comp off is not annual leave or absence' => [new AttendanceDayContext(null, false, false, true, null, false), AttendanceStatus::CompensatoryOff],
            'approved annual leave is not absent' => [new AttendanceDayContext(null, false, false, false, AttendanceStatus::ApprovedLeave, false), AttendanceStatus::ApprovedLeave],
            'approved unpaid leave is explicit' => [new AttendanceDayContext(null, false, false, false, AttendanceStatus::UnpaidLeave, false), AttendanceStatus::UnpaidLeave],
            'missing evidence on working day is absent' => [new AttendanceDayContext(null, false, false, false, null, false), AttendanceStatus::Absent],
            'short worked day is half day' => [new AttendanceDayContext(null, false, false, false, null, true, 180, 0, 240), AttendanceStatus::HalfDay],
            'late punch is late' => [new AttendanceDayContext(null, false, false, false, null, true, 480, 15, 240), AttendanceStatus::Late],
        ];
    }
}
