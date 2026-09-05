<?php

namespace App\DTOs\Hikvision;

use App\DTOs\Attendance\AttendanceImportEvent;

final readonly class HikvisionEventClassification
{
    public function __construct(
        public ?AttendanceImportEvent $event,
        public ?string $skipReason = null,
    ) {}

    public function qualifies(): bool
    {
        return $this->event !== null;
    }
}
