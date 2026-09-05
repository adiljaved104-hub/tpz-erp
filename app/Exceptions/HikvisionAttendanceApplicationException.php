<?php

namespace App\Exceptions;

use RuntimeException;

class HikvisionAttendanceApplicationException extends RuntimeException
{
    public function __construct(public readonly string $evidenceResult)
    {
        parent::__construct('Imported biometric evidence could not be applied to daily Attendance.');
    }
}
