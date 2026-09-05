<?php

namespace App\DTOs\Hikvision;

use App\Models\BiometricAttendanceSyncRun;

final readonly class HikvisionSyncResult
{
    public function __construct(public BiometricAttendanceSyncRun $run) {}
}
