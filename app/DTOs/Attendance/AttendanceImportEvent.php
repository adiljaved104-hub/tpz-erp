<?php

namespace App\DTOs\Attendance;

use Carbon\CarbonImmutable;

final readonly class AttendanceImportEvent
{
    /** @param array<string, scalar|null> $safeMetadata */
    public function __construct(
        public string $source,
        public ?string $sourceEventId,
        public string $externalEmployeeIdentifier,
        public ?string $deviceIdentifier,
        public CarbonImmutable $punchedAt,
        public ?string $punchType,
        public array $safeMetadata,
        public string $idempotencyKey,
    ) {}
}
