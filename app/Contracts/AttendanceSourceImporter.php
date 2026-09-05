<?php

namespace App\Contracts;

use App\DTOs\Attendance\AttendanceImportEvent;
use Carbon\CarbonImmutable;

interface AttendanceSourceImporter
{
    public function source(): string;

    /** @return iterable<AttendanceImportEvent> */
    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
