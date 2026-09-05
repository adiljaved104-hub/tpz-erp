<?php

namespace App\DTOs\Usage;

final readonly class UsageCheckResult
{
    /** @param array<int, string> $sources */
    public function __construct(
        public bool $used,
        public array $sources = [],
    ) {}

    public static function unused(): self
    {
        return new self(false);
    }
}
