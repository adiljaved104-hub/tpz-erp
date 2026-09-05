<?php

namespace App\DTOs\Hikvision;

final readonly class HikvisionConnectionResult
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public bool $connected,
        public string $status,
        public string $message,
        public array $metadata = [],
    ) {}
}
