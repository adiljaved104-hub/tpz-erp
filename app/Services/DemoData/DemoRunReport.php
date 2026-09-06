<?php

namespace App\Services\DemoData;

final readonly class DemoRunReport
{
    /** @param array<string, int> $counts */
    public function __construct(
        public bool $dryRun,
        public array $counts,
        public string $database,
    ) {}
}
