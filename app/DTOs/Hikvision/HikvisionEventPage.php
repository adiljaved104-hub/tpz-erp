<?php

namespace App\DTOs\Hikvision;

final readonly class HikvisionEventPage
{
    /** @param array<int, array<string, mixed>> $events */
    public function __construct(
        public array $events,
        public bool $hasMore,
        public int $nextPosition,
    ) {}
}
