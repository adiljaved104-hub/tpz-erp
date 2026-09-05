<?php

namespace App\DTOs\Hikvision;

final readonly class BiometricEmployeeMappingResult
{
    /** @param array<string, int> $pendingReasons */
    public function __construct(
        public int $associatedEventCount,
        public int $processedEventCount,
        public int $pendingEventCount,
        public array $pendingReasons = [],
    ) {}
}
